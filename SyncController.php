<?php
#ON 172.16.2.141 MASTER SERVER
#GENERATE KEYS FOR ROOT USER
#sudo su
#cd ~/.ssh
#ssh-keygen -f ~/.ssh/id_rsa -q -P ""
#COPY ID TO DESTINATION SERVER
#ssh-copy-id c1n-deploy@172.16.2.36 && ssh-copy-id c1n-deploy@172.16.2.48
#NOW YOU CAN RSYNC AS ROOT USER TO OTHER SERVERS
#BUT WE HAVE TO GIVE WWW-DATA RIGHTS TO CALL THIS SCRIPT AND ONLY THIS SCRIPT
#sudo visudo
#ADD THIS LINE
#www-data ALL=(ALL) NOPASSWD:/var/www/scripts/rsync.php,/var/www/scripts/cache.php
#MAY NEED TO EDIT /etc/passwd TO ALLOW su www-data TO TEST BUT MAKE SURE TO RETURN TO nologin
#MAKE SURE /var/www/scripts/rsync.php EXISTS AND CONTAINS RSYNC CODE PHP ZEND CONTROLLER WILL CALL INTO THIS TO RUN SYNC
#THE ABOVE IS SAFE BECAUSE www-data ONLY HAS RIGHTS TO EXECUTE ONE SCRIPT AND THERE ARE
#NO GET PARAMETERS OR POST PARAMETERS DETERMINING INPUT FOR CLI SCRIPT

class Admin_CrpsyncController extends Zend_Controller_Action
{
	private static $_connection = null;
	private static $_loadBalancerConfig = null;
	private static $_user = 'c1n-deploy';
	private static $_pwd = 'creepers78Win52piano';
	private static $_copyList = [];
	private static $_syncRecord = '';
	private static $_runTypeRsync = true;
	private static $_remoteImagePath = null;
	private static $_debug = false;
	private static $_hmac = '';

	public function init()
	{
		$this->view->placeholder('section')->set("detailview");
		$auth = Zend_Auth::getInstance()->setStorage(new Zend_Auth_Storage_Session('admin'));
		$acl_status = Zend_Registry::get('admin_acl')->isAllowed($auth->getIdentity()->role, 'cms', 'view');
		if ($acl_status) {
			$this->view->user = $auth->getIdentity();
			$this->view->placeholder('logged_in')->set(true);

			//check if they have accepted latest licenses
			$mapper = new Admin_Model_Mapper_AdminUserLicense();
			if (!$mapper->checkUpToDate($this->view->user->id)) {
				$this->getResponse()->setRedirect("/admin/license");
			}
		} else {
			$auth->clearIdentity();
			Cny_Auth::storeRequestAndAuthenticateUser();
		}

		self::$_debug = $this->getRequest()->getParam('debug',false) === 'true' ? true : false;

		if (self::isMasterServer() === false) {
			return $this->getResponse()->setRedirect($this->getRequest()->getHeader('referer'));
		}

		self::$_hmac = hash_hmac(
			"sha256",
			"MpkXasfVVvpBCXKRJsVBNkzUhuBb7cHm4hCPsr6C29Zk8",
			"TjfDtNWX7VEWd3K2k7MzkpkmU7TDnLF2zQ8C9aedxCPMfzKG8xW2NwTBk3PEAcyzBb4DLcwb8bweAEmeDMVfeZYGYYL2bav6B4kvKUhJCxusKgWTtMvQvgAnDww5kfJ52nteLYH3QXVquxLUJcjwudkEfe4XWb",
			false
		);

	}

	public function cacheAction()
	{
		try {

			$options = new Zend_Config_Ini(realpath(APPLICATION_PATH.'/configs/application.ini'), APPLICATION_ENV);

			$cacheDir = $options->get('dir')->get('cache');

			if($cacheDir === null) {
				return $this->getResponse()->setRedirect($this->getRequest()->getHeader('referer'));
			}

			$cache = Zend_Cache::factory(
				'Core',
				'File',
				['automatic_serialization' => true, 'lifetime' => 0],
				['cache_dir' => $cacheDir]
			);

			$cache->clean(Zend_Cache::CLEANING_MODE_ALL);


			foreach (self::$_loadBalancerConfig->slaves as $slave) {

				$command = ('sudo /var/www/scripts/cache.php ' . '-c "' . $slave->get('cache') . '" -u "' . $slave->get('user') . '" -h "' . $slave->get('host') . '" -k "' . self::$_hmac . '"');

				$output = shell_exec($command);

				if (self::$_debug === true) echo $command . " => " . $output . "<br>";

			}
		} catch(Exception $e) {

			error_log($e->getMessage());
			return $this->getResponse()->setRedirect($this->getRequest()->getHeader('referer'));

		}

		if(self::$_debug === true) exit;

		return $this->getResponse()->setRedirect($this->getRequest()->getHeader('referer'));
	}

	public function imagesAction()
	{
		try {

			self::$_runTypeRsync = $this->getRequest()->getParam('runType',false) === 'rsync' ? true : true;

			if (self::isMasterServer() === false) {
				return $this->getResponse()->setRedirect($this->getRequest()->getHeader('referer'));
			}

			#get list of local files to be sent
			self::buildCopyList();

			foreach (self::$_loadBalancerConfig->slaves as $slave) {

				if(self::$_debug === true) echo "COPYING TO SERVER: " . $slave->get('host'). "<br>";

				if(self::$_runTypeRsync === true) {

					self::runRsync($slave->get('user'),$slave->get('host'),$slave->get('image'));

				} else {

					#reset the remote connection
					self::getRemoteConnection($slave->get('host'), $forceNewHost = true);

					#send the files
					self::runSync($slave->get('image'));
				}

			}

			if(self::$_debug === true) exit;

			return $this->getResponse()->setRedirect($this->getRequest()->getHeader('referer'));

		} catch(Exception $e) {

			error_log($e->getMessage());
			return $this->getResponse()->setRedirect($this->getRequest()->getHeader('referer'));

		}
	}

	public static function isMasterServer()
	{
		$config = Zend_Registry::get('configuration');

		if(!isset($_SERVER['SERVER_ADDR'])
			|| !isset($config->load_balancer)
			|| !isset($config->load_balancer->master)
		) return false;

		self::$_loadBalancerConfig = $config->load_balancer;

		return $_SERVER['SERVER_ADDR'] === $config->load_balancer->master->get('host');
	}

	public static function runRsync($user,$host,$remote)
	{
		$local = self::getLocalImagePath();
		$command = 'sudo /var/www/scripts/rsync.php -l "'.$local.'/*" -u "'.$user.'" -h "'.$host.'" -r "'.$remote . '" -k "' . self::$_hmac . '"';

		if(self::$_debug === true) {
			echo $command . "<br>";
		} else {
			$output = shell_exec($command);
			return $output;
		}
	}

	public static function runSync($remotePath)
	{
		if(empty(self::$_copyList)) return;

		self::$_remoteImagePath = $remotePath;

		foreach(self::$_copyList as $glib) {

			self::createRemotedirectoryIfNoneExists($glib);

			if(self::$_debug === true) echo $glib . " => " . self::getRemoteFileName($glib) . "<br>";

			self::copyLocalFileToRemote($glib,self::getRemoteFileName($glib));

		}
	}

	public static function getRemoteConnection($host = '', $forceNewHost = false)
	{
		if($forceNewHost === true || self::$_connection === null) {

			if($forceNewHost === true && self::$_connection !== null) {

				ssh2_exec(self::$_connection, 'exit');
			}

			self::$_connection = ssh2_connect($host, 22);

			ssh2_auth_password(self::$_connection, self::$_user, self::$_pwd);
		}

		return self::$_connection;
	}

	public static function runRemoteCommandCaptureOutput($command)
	{
		$connection = self::getRemoteConnection();

		$stream = ssh2_exec($connection, $command);

		stream_set_blocking($stream, true);

		$stream_out = ssh2_fetch_stream($stream, SSH2_STREAM_STDIO);

		return trim(preg_replace('#\n$#','',stream_get_contents($stream_out)));
	}

	public static function getRemoteImagePath()
	{
		return self::$_remoteImagePath;
	}

	public static function getLocalImagePath()
	{
		if(self::$_loadBalancerConfig->master->get('image') === null) {
			throw new Exception('There is no master server configured for this environment');
		}

		return self::$_loadBalancerConfig->master->get('image');
	}

	public static function strip($str)
	{
		return preg_replace('/[^A-Za-z0-9]+/','',$str);
	}

	public static function getRemoteFileName($file)
	{
		return preg_replace('#'.self::getLocalImagePath().'#',self::getRemoteImagePath(),$file);
	}

	public static function getRemoteDirectoryName($file)
	{
		return preg_replace('#'.self::getLocalImagePath().'#',self::getRemoteImagePath(),preg_replace('#/[^/]*$#', '', $file));
	}

	public static function remoteDirectoryExists($directory)
	{
		$command = '[ -d "' . $directory . '" ] && echo \'true\' || echo \'false\'';
		$return  = self::runRemoteCommandCaptureOutput($command);
		return $return === 'true' ? true : false;
	}

	public static function createRemotedirectoryIfNoneExists($directory)
	{
		$directory = self::getRemoteDirectoryName($directory);
		if(self::remoteDirectoryExists($directory) === true) {
			return;
		}
		self::runRemoteCommandCaptureOutput('mkdir -p "'.$directory.'"');
	}

	public static function copyLocalFileToRemote($local,$remote)
	{
		$connection = self::getRemoteConnection();

		return ((bool) ssh2_scp_send($connection, $local, $remote));
	}

	public static function getLastSyncTime()
	{
		return strtotime("-1 hours");
	}

	public static function writeLastSyncTime()
	{
		$snyc = fopen(self::$_syncRecord, "w");

		fwrite($snyc, time());

		fclose($snyc);
	}

	public static function buildCopyList($directory = null)
	{
		if($directory === null) {
			$directory = self::getLocalImagePath();
		}

		foreach (glob("$directory/*") as $glib) {

			if (is_dir($glib) === true) {
				self::buildCopyList($glib);
				continue;
			}

			$fMTime = filemtime($glib);

			if ($fMTime > self::getLastSyncTime()) {

				self::$_copyList[] = $glib;
			}
		}
	}
}
