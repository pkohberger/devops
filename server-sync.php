<?php

class ImageSync
{

	private static $_syncRecord = '';
	private static $_fileCount = 1;
	private static $_lastSyncTime = null;
	private static $_user = 'c1n-pk';
	private static $_pwd = 'XXXXXXXXXXXX';
	private static $_host = '456456456456' ;
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

		return stream_get_contents($stream_out);
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
			self::$_remoteImagePath = '/var/www/central/public/userFiles';
		}
		return self::$_remoteImagePath;
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

	public static function getFilesModifiedTime($directory = null, $i = 0)
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

				echo self::$_fileCount++ . $glib . " $fMTime<br>";

			}
		}
	}

	public static function syncImages()
	{
		self::getFilesModifiedTime();
		var_dump(self::runRemoteCommandCaptureOutput('mkdir -p "/home/c1n-pk/thumbs/butterbeans/treesho/scooter dooter pie"'));exit;
		$connection = self::getRemoteConnection();
		ssh2_sftp_mkdir($connection, '"/home/c1n-pk/thumbs/hgg"', 0777,true);

	}
}

ImageSync::syncImages();
