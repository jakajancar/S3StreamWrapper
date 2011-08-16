<?php

class S3StreamWrapper
{
    private $accessKey;
    private $secretKey;
    private $bucket;
    private $path;
    
    const MODE_STREAM_READ = 'MODE_STREAM_READ';
    const MODE_STREAM_WRITE = 'MODE_STREAM_WRITE';
    const MODE_DIR = 'MODE_DIR';
    private $mode;

    // MODE_STREAM_READ only:
    private $readStream; // underlying connection
    
    // MODE_STREAM_WRITE only:
    private $writeBuffer; // temporary file
    private $writeLength;
    private $writeHashingContext;
    private $writeFlushed;
    
    // MODE_DIR only:
    private $dirNextMarker; // string if more may be available, false otherwise
    private $dirBuffer;
    
    protected function getHost()
    {
        return 's3.amazonaws.com';
    }
    
    private function initialize($mode, $url)
    {
        if (isset($this->mode)) {
            trigget_error("Wraper already initialized to mode $this->mode, cannot initialize it to $mode.", E_USER_ERROR);
            return false;
        }
        
        $this->mode = $mode;
        
        $parsed = parse_url($url);
        if (!isset($parsed['user']) || !isset($parsed['pass'])) {
            trigger_error('Access Key ID or Secret Access Key not set in URL', E_USER_WARNING);
            return false;
        }
        
        $this->accessKey = urldecode($parsed['user']);
        $this->secretKey = urldecode($parsed['pass']);
        $this->bucket = $parsed['host'];
        $this->path = self::normalize(isset($parsed['path']) ? $parsed['path'] : '/');
        
        return true;
    }
    
    // TODO: Trigger errors only if $options & STREAM_REPORT_ERRORS
    // TODO: Take into account $options & STREAM_USE_PATH and use $opened_path
    // TODO: Add context option that disables 'filesystem' behavior (path normalization etc).
    public function stream_open($path, $mode, $options, &$opened_path)
    {
        switch ($mode) {
            case 'r':
            case 'rb':
                if (!$this->initialize(self::MODE_STREAM_READ, $path))
                    return false;
                
                $this->readStream = $this->request('GET', $this->path, array(), null, 0, $respCode, $respHeaders);
                if ($respCode !== 200) {
                    trigger_error("File '{$this->bucket}{$this->path}' does not exist.", E_USER_WARNING);
                    return false;
                }
                
                return true;
            case 'x':
            case 'xb':
                if (file_exists($path)) {
                    trigger_error("File '$path' already exists, not overwriting it.", E_USER_WARNING);
                    return false;
                }
                // break intentionally omitted
            case 'w':
            case 'wb':
                if (!$this->initialize(self::MODE_STREAM_WRITE, $path))
                    return false;
                
                $this->writeBuffer = tmpfile();
                $this->writeLength = 0;
                $this->writeHashingContext = hash_init('md5');
                $this->writeFlushed = false;
                
                return true;
            default:
                trigger_error('Unsupported mode. Only r/rb/w/wb are supported.', E_USER_WARNING);
                return false;
        }
    }
    
    public function stream_read($count)
    {
        if ($this->mode !== self::MODE_STREAM_READ) {
            trigger_error('Cannot read, stream was not opened for reading.', E_USER_WARNING);
            return false;
        }
        
        return fread($this->readStream, $count);
    }
    
    public function stream_eof()
    {
        if ($this->mode !== self::MODE_STREAM_READ) {
            trigger_error('Cannot detect eof, stream was not opened for reading.', E_USER_WARNING);
            return false;
        }
        
        return feof($this->readStream);
    }
    
    public function stream_stat()
    {
        // Call url_stat here? This is called while file is open for writing,
        // and size, mtime, etc. wouldn't be right.
        return false;
    }
    
    public function stream_write($data)
    {
        if ($this->mode !== self::MODE_STREAM_WRITE) {
            trigger_error('Cannot write, stream was not opened for writing.', E_USER_WARNING);
            return false;
        }
        
        if ($this->writeFlushed) {
            trigger_error('Cannot write, stream was already flushed.', E_USER_WARNING);
            return false;
        }
        
        $cnt = fwrite($this->writeBuffer, $data);
        if ($cnt !== false) {
            $this->writeLength += $cnt;
            hash_update($this->writeHashingContext, substr($data, 0, $cnt));
        }
        return $cnt;
    }
    
    // Also called automatically on stream_close by PHP.
    public function stream_flush()
    {
        if ($this->mode !== self::MODE_STREAM_WRITE)
            return false;
        
        if ($this->writeFlushed)
            return false;
        
        $this->writeFlushed = true;
        
        $md5 = base64_encode(hash_final($this->writeHashingContext, true));
        //echo "Uploading $this->writeLength bytes, hash: $md5\n";
        
        fseek($this->writeBuffer, 0);
        $respBodyStream = $this->request('PUT', $this->path, array('Content-MD5'=>$md5), $this->writeBuffer, $this->writeLength, $respCode);
        fclose($this->writeBuffer);
        $this->writeBuffer = null;
        
        $respBody = stream_get_contents($respBodyStream);
        fclose($respBodyStream);
        
        if ($respCode !== 200) {
            trigger_error("Could not store object to S3, PUT resulted in code $respCode: ".$respBody, E_USER_WARNING);
            return false;
        }
        
        return true;
    }
    
    public function stream_cast($cast_as)
    {
        if ($this->mode === self::MODE_STREAM_READ)
            return $this->readStream;
        
        // Must not return writeBuffer or writes won't go through stream_write
        // and thus won't be hashed.
        
        return false;
    }
    
    public function stream_close()
    {
        // Not sure if these objects are reused... should we reset ivars?
        switch ($this->mode) {
            case self::MODE_STREAM_READ:
                fclose($this->readStream);
                break;
            case self::MODE_STREAM_WRITE:
                $this->stream_flush(); // should be called automatically, but just to be sure
                break;
        }
    }
    
    public function url_stat($path, $flags)
    {
        if (!$this->initialize(null, $path))
            return false;
        
        $fp = $this->request('HEAD', $this->path, array(), null, 0, $respCode, $respHeaders);
        fclose($fp);
        
        if ($respCode === 404) {
            if (!($flags & STREAM_URL_STAT_QUIET))
                trigger_error("File does not exist: '{$this->bucket}{$this->path}'", E_USER_WARNING);
            return false;
        }
        
        if ($respCode !== 200) {
            if (!($flags & STREAM_URL_STAT_QUIET))
                trigger_error("Error doing url_stat() on '{$this->bucket}{$this->path}', got code $respCode", E_USER_WARNING);
            return false;
        }
        
        $ret = array(
            'dev'     => 0,
            'ino'     => 0,
            'mode'    => 0,
            'nlink'   => 0,
            'uid'     => 0,
            'gid'     => 0,
            'rdev'    => 0,
            'size'    => (int)$respHeaders['CONTENT-LENGTH'],
            'atime'   => time(),
            'mtime'   => strtotime($respHeaders['LAST-MODIFIED']),
            'ctime'   => strtotime($respHeaders['LAST-MODIFIED']),
            'blksize' => -1,
            'blocks'  => -1,
        );
        $ret = array_merge(array_values($ret), $ret);
        return $ret;
    }
    
    public function unlink($path)
    {
        if (!$this->initialize(null, $path))
            return false;
        
        $fp = $this->request('DELETE', $this->path, array(), null, 0, $respCode, $respHeaders);
        fclose($fp);
        
        if ($respCode === 404) {
            trigger_error("File does not exist: '{$this->bucket}{$this->path}'", E_USER_WARNING);
            return false;
        }
        
        if ($respCode !== 204) {
            trigger_error("Error doing unlink() on '{$this->bucket}{$this->path}', got code $respCode", E_USER_WARNING);
            return false;
        }
    }
    
    public function dir_opendir($path, $options)
    {
        if (!$this->initialize(self::MODE_DIR, $path))
            return false;
        
        $this->dir_rewinddir();
        
        return true;
    }
    
    public function dir_readdir()
    {
        if ($this->mode !== self::MODE_DIR) {
            trigger_error('Cannot readdir, not in MODE_DIR.', E_USER_WARNING);
            return false;
        }
        
        if (empty($this->dirBuffer) && $this->dirNextMarker !== false) {
            // Load another chunk
            
            // Must have trailing slash (otherwise for dir/ you could also list di/).
            // Exception is '/', which must use an empty prefix.
            $prefix = trim($this->path, '/');
            if ($prefix !== '')
                $prefix .= '/';
            
            $respBodyStream = $this->request('GET', '/?prefix='.urlencode($prefix).'&delimiter='.urlencode('/').'&marker='.urlencode($this->dirNextMarker), array(), null, 0, $respCode, $respHeaders);
            $respBody = stream_get_contents($respBodyStream);
            fclose($respBodyStream);
            
            if ($respCode !== 200) {
                trigger_error("Could not read directory, request resulted in code $respCode: ".$respBody, E_USER_WARNING);
                return false;
            }
            
            $resp = simplexml_load_string($respBody);
            $this->dirNextMarker = $resp->IsTruncated == 'true' ? (string)$resp->NextMarker : false;
            
            foreach ($resp->Contents as $c) {
                if ((string)$c->Key === $prefix)
                    continue; // there is a file for the directory itself, ignore
                    
                $part = substr($c->Key, strlen($prefix));
                
                // TODO: Cache metadata?
                $this->dirBuffer[] = $part;
            }

            foreach ($resp->CommonPrefixes as $c) {
                assert('substr($c->Prefix, -1) === "/"');
                $part = substr($c->Prefix, strlen($prefix), -1);
                
                if ($part === '')
                    continue; // empty path components not supported by filesystems
                $this->dirBuffer[] = $part;
            }
        }
        
        if (!empty($this->dirBuffer)) {
            // Entry available in buffer.
            return array_shift($this->dirBuffer);
        } else {
            return false;
        }
    }
    
    public function dir_rewinddir()
    {
        if ($this->mode !== self::MODE_DIR) {
            trigger_error('Cannot rewinddir, not in MODE_DIR.', E_USER_WARNING);
            return false;
        }
        
        $this->dirNextMarker = '';
        $this->dirBuffer = array('.', '..');
        
        return true;
    }
    
    public function dir_closedir()
    {
        if ($this->mode !== self::MODE_DIR) {
            trigger_error('Cannot closedir, not in MODE_DIR.', E_USER_WARNING);
            return false;
        }
        
        $this->dirNextMarker = null;
        $this->dirBuffer = null;
    }
    
    // Writes bodyStream as request body, but doesn't close the stream.
    // Returns a stream for the response body.
    // This uses tcp transport instead of http, because http doesn't allow streaming of request body.
    // TODO: Handle redirects!
    // TODO: Add option to read the body into string instead of returning a stream.
    private function request($method, $path, $headers=array(), $bodyStream=null, $bodyLength=0, &$respCode=null, &$respHeaders=array())
    {
        $host = $this->bucket.'.'.$this->getHost();
        
        // Perhaps it would be faster to temporarily set default time zone?
        $now = new DateTime('now', new DateTimeZone('UTC'));
        
        // Build headers
        $headers = array_merge($headers, array(
            'Host'           => $host,
            'User-Agent'     => 'PHPS3StreamWrapper/0.1',
            'Date'           => $now->format('D, j M Y H:i:s').' GMT',
            'Content-Type'   => 'application/octet-stream',
            'Content-Length' => $bodyLength,
            'Connection'     => 'close',
        ));
        
        // Calculate signature and add it to headers
        $stringToSign = $method . "\n"
                      . (isset($headers['Content-MD5'])  ? $headers['Content-MD5']  : '') . "\n"
                      . (isset($headers['Content-Type']) ? $headers['Content-Type'] : '') . "\n"
                      . $headers['Date'] . "\n"
                      . '/' . $this->bucket . preg_replace('/\?.*/', '', $path); // add subresource if we have one
                      
        $headers['Authorization']  = 'AWS '.$this->accessKey.':'.base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));
        // php -r 'echo pack("H*" , str_replace(" ", "", ""));'
        
        // Send request
        $fp = stream_socket_client("tcp://$host:80");
        if (!$fp) {
            trigger_error("Cannot connect to S3 server ($host).", E_USER_WARNING);
            return false;
        }
        
        $req = "$method $path HTTP/1.1\r\n";
        foreach ($headers as $k=>$v)
            $req .= "$k: $v\r\n";
        $req .= "\r\n";
        fwrite($fp, $req);

        if ($bodyStream)
            stream_copy_to_stream($bodyStream, $fp);
        
        // Read up to body
        $responseLines = array();
        
        // Bufferless read avoids "<n> bytes of buffered data lost during stream
        // conversion!" when using the returned stream in external libs (e.g. zlib).
        // Slow :'(
        stream_set_read_buffer($fp, 0);
        while ($line = trim(self::fgets_unbuffered($fp))) {
            $responseLines[] = $line;
        }
        stream_set_read_buffer($fp, 8192);
        
        // Parse status line
        list($respProtocol, $respCode, $respReason) = explode(' ', array_shift($responseLines), 3);
        $respCode = (int)$respCode;

        // Parse headers
        $respHeaders = array();
        foreach ($responseLines as $line) {
            list($k, $v) = explode(':', $line, 2);
            $respHeaders[strtoupper($k)] = trim($v);
        }
        
        if (@$respHeaders['TRANSFER-ENCODING'] === 'chunked')
            stream_filter_append($fp, 'dechunk', STREAM_FILTER_READ);
        
        return $fp;
    }
    
    /**
     * Normalizes the path so we get more filesystem-like behavior.
     *
     * This function does several operations to the given path:
     *   * Removes unnecessary slashes (///path//to/////directory////)
     *   * Removes current directory references (/path/././to/./directory/./././)
     *   * Resolves relative paths (/path/from/../to/somewhere/in/../../directory)
     *
     * The returned path will always begin with '/' and will end with a slash only
     * if the original one did or was empty.
     *
     * Derived from: http://www.liranuna.com/php-path-resolution-class-relative-paths-made-easy/
     *
     * @arg $path    The path to normalize
     */
    public static function normalize($path) {
        $ret = array_reduce(explode('/', $path), function($a, $b) {
            if($b === "" || $b === ".")
                return $a;

            if($b === "..")
                return dirname($a);

            return preg_replace("/\/+/", "/", "$a/$b");
        }, '/');
        
        // Add trailing slash if it was originally present (and it now ins't)
        if (substr($ret, -1) !== '/' && substr($path, -1) === '/')
            $ret .= '/';
        
        return $ret;
    }
    
    private function fgets_unbuffered($fp)
    {
        $buf = '';
        while (!feof($fp)) {
            $c = fread($fp, 1);
            if ($c === false)
                return false;
            $buf .= $c;
            if ($c === "\n")
                break;
        }
        return $buf;
    }
}
