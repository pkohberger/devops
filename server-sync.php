<?php

#sudo apt install php-ssh2
#sudo service apache2 restart
#https://www.digitalocean.com/community/tutorials/how-to-configure-ssh-key-based-authentication-on-a-linux-server
#https://www.digitalocean.com/community/tutorials/how-to-copy-files-with-rsync-over-ssh
#ssh-keygen -f ~/.ssh/id_rsa -q -P ""
#ssh-copy-id c1n-pk@172.16.2.180

class ImageSync
{

	private static $_syncRecord = '';
	private static $_fileCount = 1;
	private static $_lastSyncTime = null;
	private static $_user = 'c1n-pk';
	private static $_pwd = 'e&!Ep$TpeYq!5LEk';
	private static $_host = '172.16.2.180' ;
	private static $_connection = null;
	private static $_localImagePath = null;
	private static $_remoteImagePath = null;

	public static function getRemoteConnection()
	{
		if(self::$_connection === null) {

			self::$_connection = ssh2_connect(self::$_host, 22);

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

	public static function getLocalImagePath()
	{
		if(self::$_localImagePath === null) {
			self::$_localImagePath = $_SERVER['DOCUMENT_ROOT'] . '/userFiles';
		}
		return self::$_localImagePath;
	}

	public static function getRemoteImagePath()
	{
		if(self::$_remoteImagePath === null) {
			self::$_remoteImagePath = '/home/c1n-pk/userFiles';#'/var/www/central/public/userFiles';
		}
		return self::$_remoteImagePath;
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
		self::$_syncRecord = $_SERVER['DOCUMENT_ROOT'] . '/../tmp/server.sync';

		if(self::$_lastSyncTime === null) {

			if (file_exists(self::$_syncRecord)) {

				self::$_lastSyncTime = time(file_get_contents(self::$_syncRecord));

			} else {

				self::$_lastSyncTime = 0;

			}
		}
		return 1529605500;
		return self::$_lastSyncTime;
	}

	public static function writeLastSyncTime()
	{
		$snyc = fopen(self::$_syncRecord, "w");

		fwrite($snyc, time());

		fclose($snyc);
	}

	public static function getFilesModifiedTime($directory = null)
	{
		if($directory === null) {
			$directory = self::getLocalImagePath();
		}

		foreach (glob("$directory/*") as $glib) {

			if (is_dir($glib) === true) {
				self::getFilesModifiedTime($glib);
				continue;
			}

			$fMTime = filemtime($glib);

			if ($fMTime > self::getLastSyncTime()) {

				self::createRemotedirectoryIfNoneExists($glib);

				self::copyLocalFileToRemote($glib,self::getRemoteFileName($glib));
			}
		}
	}

	public static function runRsync()
	{
		$local = self::getLocalImagePath();
		$remote = self::getRemoteImagePath();
		$command = "rsync -cr $local/* " . self::$_user . "@" . self::$_host . ":$remote";
		$output = shell_exec($command);
		echo $output;
	}

	public static function syncImages()
	{
		self::runRsync();
		#self::getFilesModifiedTime();
	}
}

ImageSync::syncImages();
