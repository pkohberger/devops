#!/usr/bin/php 
<?php

$options = getopt('l:u:h:r:k:');

if(!isset($options['l']) || trim($options['l']) === '') {
	exit;
}

if(!isset($options['u']) || trim($options['u']) === '') {
	exit;
}

if(!isset($options['h']) || trim($options['h']) === '') {
	exit;
}

if(!isset($options['r']) || trim($options['r']) === '') {
	exit;
}

if(!isset($options['k']) || trim($options['k']) === '') {
        exit;
}

$hMac = hash_hmac(
	"sha256",
	"MpkXasfVVvpBCXKRJsVBNkzUhuBb7cHm4hCPsr6C29Zk8",
	"TjfDtNWX7VEWd3K2k7MzkpkmU7TDnLF2zQ8C9aedxCPMfzKG8xW2NwTBk3PEAcyzBb4DLcwb8bweAEmeDMVfeZYGYYL2bav6B4kvKUhJCxusKgWTtMvQvgAnDww5kfJ52nteLYH3QXVquxLUJcjwudkEfe4XWb",
	false
);

if($options['k'] !== $hMac) {
	exit;
}

$cleanLocal = preg_replace('/[^A-Za-z0-9]+/','',$options['l']);
$cleanRemote = preg_replace('/[^A-Za-z0-9]+/','',$options['r']);

if($cleanLocal === $cleanRemote) {

	$command = 'rsync -crog ' . $options['l'] . ' ' . $options['u'] . '@' . $options['h'] . ':' . $options['r'] . ' --delete';
	shell_exec($command);
}


