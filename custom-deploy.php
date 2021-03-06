<?php

class DeployManager
{

	private static $_options = [];
	private static $_user;
	private static $_pwd;
	private static $_connection = null;
	private static $_APPLICATION_PATH;

	public static function getDeployEnv()
	{
		$options = self::$_options;

		$exception = '';

		if (isset($options['e'])) {

			$env = $options['e'];

		} else {

			$exception = "ENVIRONMENT -e parameter missing from cli call\n";

		}

		$deployFileReader = file_exists(self::$_APPLICATION_PATH . "scripts/custom-deploy.json")
			? self::$_APPLICATION_PATH . "scripts/custom-deploy.json" : self::$_APPLICATION_PATH . "public/custom-deploy.json";

		$deploy = file_get_contents($deployFileReader);

		$deploy = json_decode($deploy, true);

		if(isset($deploy['CRED']) && isset($deploy['CRED']['USER'])) {

			self::$_user = $deploy['CRED']['USER'];
		} else {
			$exception = "CRED:USER key missing from custom-deploy.json file\n";
		}

		if(isset($deploy['CRED']) && isset($deploy['CRED']['PWD'])) {

			self::$_pwd = $deploy['CRED']['PWD'];
		} else {
			$exception = "CRED:PWD key missing from custom-deploy.json file\n";
		}

		if(isset($deploy['ENV']) && isset($deploy['ENV'][$env])) {

			if($exception !== '') {
				throw new Exception($exception);
			}

			return $deploy['ENV'][$env];

		}

		$exception .= "ENV:$env key missing from custom-deploy.json file\n";

		throw new Exception($exception);
	}

	public static function getDeploymentFiles()
	{
		$options =  self::$_options;

		$hashes = [];
		if (isset($options['s'])) {

			$hashes = shell_exec("git log --pretty=format:\"%H\" --grep=" . $options['s']);

			$hashes = explode("\n", $hashes);

		} elseif (!isset($options['h'])) {

			$hash = trim(exec('git rev-parse --verify HEAD'));

		} else {

			$hash = $options['h'];

		}

		if(empty($hashes)) {

			$hashes = [$hash];

		}

		$data = [];
		foreach($hashes as $hash) {

			$files = shell_exec("git show --name-only --pretty=format: $hash");

			$files = explode("\n", $files);

			foreach ($files as $file) {

				if (trim($file) != '') {

					$cleaned = preg_replace('#[^A-Za-z0-9]+#','',$file);

					$data[$cleaned] = $file;
				}
			}
		}

		return $data;

	}

	public static function getCopyPath($path, $file)
	{
		return $path . $file;
	}

	public static function printExecutionPathAndCopyIfPermitted()
	{
		self::$_APPLICATION_PATH = getcwd() . '/';

		self::$_options = getopt('h:e:s:d:a:');

		$envs = self::getDeployEnv();

		$files = self::getDeploymentFiles();

		echo "\n\nDeployment Path:\n\n\n";

		foreach ($envs as $host => $path) {

			foreach ($files as $file) {

				$copyPath = self::getCopyPath($path, $file);

				echo self::$_APPLICATION_PATH . $file . " => $host:$copyPath \n\n";

			}
		}

		echo "Type 'y' to continue: ";
		$handle = fopen("php://stdin", "r");
		$line = fgets($handle);
		if (trim($line) != 'y') {
			echo "\n\n\n\n";
			echo "Aborting Deployment!";
			echo "\n\n\n\n";
			exit;
		}
		fclose($handle);
		echo "\n\n\n\n";
		echo "Thank you, deploying...";
		echo "\n\n\n\n";

		self::deployFiles();

		if(isset(self::$_options['a'])) {

			self::compileCustomAssets();

			self::customAssetsRunRsync();

		}
	}

	public static function deployFiles()
	{
		$options = self::$_options;

		$diff = false;
		if(isset($options['d']) && ((bool)$options['d']) === true) {
			$diff = true;
		}

		$envs = self::getDeployEnv();

		$files = self::getDeploymentFiles();

		$failures = [];

		foreach ($envs as $host => $path) {

			self::refreshRemoteConnection($host);

			foreach ($files as $file) {

				$copyPath = self::getCopyPath($path, $file);
				$localPath = self::$_APPLICATION_PATH . $file;

				if($diff === false) {

					echo $localPath . " => $host:$copyPath \n\n";

					$success = self::copyLocalFileToRemote($localPath, $copyPath);

					if ($success === true) {

						echo 'success';

					} else {

						$failures[] = $file;

						echo 'failure';

					}
				} elseif($diff === true) {

					echo self::runRemoteDiff($host,$localPath,$copyPath);
					;
				}

				echo "\n\n";
			}
		}

		if (count($failures) === 0) {

			echo "Your deployment was a 100% success\n\n\n\n";

		}
	}

	public static function refreshRemoteConnection($host)
	{
		self::getRemoteConnection($host,$forcenew = true);
	}

	public static function getRemoteConnection($host = '',$forcenew = false)
	{
		if($forcenew === true || self::$_connection === null) {

			if($forcenew === true && self::$_connection !== null) {

				ssh2_exec(self::$_connection, 'exit');

			}

			self::$_connection = ssh2_connect($host, 22);

			ssh2_auth_password(self::$_connection, self::$_user, self::$_pwd);
		}

		return self::$_connection;
	}

	public static function copyLocalFileToRemote($local,$remote)
	{
		$connection = self::getRemoteConnection();
		return ((bool) ssh2_scp_send($connection, $local, $remote));
	}

	public static function runRemoteCommandCaptureOutput($command)
	{
		$connection = self::getRemoteConnection();

		$stream = ssh2_exec($connection, $command);

		stream_set_blocking($stream, true);

		$stream_out = ssh2_fetch_stream($stream, SSH2_STREAM_STDIO);

		return stream_get_contents($stream_out);
	}

	public static function runRemoteDiff($host,$localFile,$remoteFile)
	{
		echo "Running diff on following files: $remoteFile => $localFile \n\n";

		$command = 'sshpass -p \'' . self::$_pwd . '\' scp ' . self::$_user . '@' . $host . ':' . $remoteFile . ' /tmp/diff.txt && colordiff /tmp/diff.txt "' . $localFile . '" --ignore-space-change --ignore-blank-lines';

		return shell_exec($command);
	}

	public static function compileCustomAssets()
	{
		$command = 'cd ' . self::$_APPLICATION_PATH . 'custom_assets/ && gulp --production';

		echo "Compiling custom asssets...\n\n\n\n";

		echo shell_exec($command);
	}

	public static function iterateCustomDirectory($directory = null, $host, $path)
	{
		if($directory === null) {
			$directory = self::getLocalImagePath();
		}

		foreach (glob("$directory/*") as $glib) {

			if(preg_match('/node_modules|bower_components/',$glib) == 1) {
				continue;
			}

			if (is_dir($glib) === true) {
				self::iterateCustomDirectory($glib,$host,$path);
				continue;
			}

			$newFile = preg_replace('#'.self::$_APPLICATION_PATH.'#',$path,$glib);

			echo "Copying ". $glib . " => " . $newFile . "\n";

			$success = self::copyLocalFileToRemote($glib,$newFile);

			if ($success === true) {

				echo "success\n";

			} else {

				echo "failure\n";

			}
		}
	}

	public static function customAssetsRunRsync()
	{
		$envs = self::getDeployEnv();

		foreach ($envs as $host => $path) {

			self::refreshRemoteConnection($host);

			self::iterateCustomDirectory(self::$_APPLICATION_PATH . 'public/custom_assets',$host,$path);
		}
	}
}

DeployManager::printExecutionPathAndCopyIfPermitted();
