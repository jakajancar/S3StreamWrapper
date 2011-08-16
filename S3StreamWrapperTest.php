#!/usr/bin/env php
<?php

error_reporting(E_ALL|E_STRICT);
ini_set('display_errors', 'stderr');
ini_set('log_errors', false);
date_default_timezone_set('UTC');

require_once 'S3StreamWrapper.php';
require_once 'GSStreamWrapper.php';

stream_wrapper_register('s3', 'S3StreamWrapper');
stream_wrapper_register('gs', 'GSStreamWrapper');

$buckets = array_slice($argv, 1);
if (empty($buckets)) {
    fwrite(STDERR, <<<EOF
Usage: {$argv[0]} <bucket-url> [bucket-url...]

Example:
    {$argv[0]} s3://access:secret@mybucket/ gs://access:secret@mybucket/


EOF
);
}

// Local tests
assert('S3StreamWrapper::normalize("///path//to/////directory////") === "/path/to/directory/"');
assert('S3StreamWrapper::normalize("/path/././to/./directory/./././") === "/path/to/directory/"');
assert('S3StreamWrapper::normalize("/path/from/../to/somewhere/in/../../directory/") === "/path/to/directory/"');

assert('S3StreamWrapper::normalize("///path//to/////file") === "/path/to/file"');
assert('S3StreamWrapper::normalize("/path/././to/./file") === "/path/to/file"');
assert('S3StreamWrapper::normalize("/path/from/../to/somewhere/in/../../file") === "/path/to/file"');

assert('S3StreamWrapper::normalize("") === "/"');
assert('S3StreamWrapper::normalize("/") === "/"');
assert('S3StreamWrapper::normalize("/..") === "/"');
assert('S3StreamWrapper::normalize("/.") === "/"');
assert('S3StreamWrapper::normalize("/./..") === "/"');
assert('S3StreamWrapper::normalize("/../.././") === "/"');

assert('S3StreamWrapper::normalize("/../.././foo/") === "/foo/"');
assert('S3StreamWrapper::normalize("/../.././bar") === "/bar"');

// Super hacky remote tests
foreach ($buckets as $bucket) {
    $title = "Testing with bucket $bucket";
    echo "$title\n";
    echo str_repeat('-', strlen($title))."\n";
    
    // TODO: Test compress.zlib, compress.bzip2
    
    $fps = array(); // here to prevet garbage collection across iterations
    foreach (array(1,2,3,4,5,6,7,8) as $i) {
        // Add a bit of variation
        if ($i == 3)
            clearstatcache();
        if ($i == 6)
            $fps = array();
        
        echo "Iteration $i\n";
        echo " - Generating random blob...\n";
        $blob = str_repeat(uniqid('', true), 1000);
        
        echo " - Storing the blob...\n";
        $fps[] = $fp = fopen("$bucket/test", 'w');
        fwrite($fp, $blob);
        assert('fflush($fp) === true');  // upload should succeed
        assert('fflush($fp) === false'); // second flush should return false
        if ($i%2) // let's forget to close occasionally
            fclose($fp);
        
        echo " - Retrieving the blob...\n";
        $fps[] = $fp = fopen("$bucket/test", 'r');
        $receivedBlob = stream_get_contents($fp);
        assert('$receivedBlob === $blob');
        if ($i%2) // let's forget to close occasionally
            fclose($fp);
        
        echo " - Testing file_exists on existent file...\n";
        $ret = file_exists("$bucket/test");
        assert('$ret === true');
        $ret = file_exists("$bucket//..//.//test");
        assert('$ret === true');
        
        echo " - Testing file_exists on none-existent file...\n";
        $ret = file_exists("$bucket/nonexistent");
        assert('$ret === false');
        $ret = file_exists("$bucket//..//.//test/"); // file exists, but file/ should not
        assert('$ret === false');
        
        echo " - Testing write with mode 'x' and an existing file...\n";
        $fp = @fopen("$bucket/test", 'x');
        assert('$fp === false');
        
        echo "\n";
    }
}
