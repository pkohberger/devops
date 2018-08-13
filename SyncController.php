<?php
class Admin_SyncController extends Zend_Controller_Action
{
	private static $_connection = null;
	private static $_loadBalancerConfig = null;
	private static $_user = 'c1n-pk';
	private static $_pwd = 'e&!Ep$TpeYq!5LEk';
	private static $_copyList = [];
	private static $_syncRecord = '';
	private static $_lastSyncTime = null;
	private static $_remoteImagePath = null;
	private static $_debug = false;

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
	}

	public function imagesAction()
	{
		try {

			self::$_debug = $this->getRequest()->getParam('debug',false) === 'true' ? true : false;

			if (self::isMasterServer() === false) {
				return $this->getResponse()->setRedirect('/admin');
			}

			#get list of local files to be sent
			self::buildCopyList();

			foreach (self::$_loadBalancerConfig->slaves as $slave) {

				if(self::$_debug === true) echo "COPYING TO SERVER: " . $slave->get('host'). "<br>";

				#reset the remote connection
				self::getRemoteConnection($slave->get('host'), $forceNewHost = true);

				#send the files
				self::runSync($slave->get('image'));

			}

			if(self::$_debug === true) exit;

			return $this->getResponse()->setRedirect('/admin');

		} catch(Exception $e) {

			error_log($e->getMessage());
			return $this->getResponse()->setRedirect('/admin');

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
