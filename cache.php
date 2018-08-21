#!/usr/bin/php
<?php

$options = getopt('c:u:h:k:');

if(!isset($options['c']) || trim($options['c']) === '') {
	exit;
}

if(!isset($options['u']) || trim($options['u']) === '') {
	exit;
}

if(!isset($options['h']) || trim($options['h']) === '') {
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

$command = ('ssh ' . $options['u'] . '@' . $options['h'] . ' ' . $options['c'] . '/scripts/cache.php');

shell_exec($command);
