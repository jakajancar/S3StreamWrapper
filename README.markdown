S3StreamWrapper
===============

These stream wrappers allow you to use S3 and Google Storage (almost) like a local file system:

  - `file_get_contents('s3://access-key:secret-key/bucket/path/to/a/file.txt');`

  - `scandir('s3://access-key:secret-key/bucket/path/to/a/dir/');`

  - `fopen(...);`

  - `stat(...);`

  - ...

Requirements
------------

  - PHP 5.3 (for the dechunk filter)
  - The Hash extension (enabled by default since PHP 5.1.2)

Usage
-----

    require_once 'S3StreamWrapper.php';
    require_once 'GSStreamWrapper.php';
    
    stream_wrapper_register('s3', 'S3StreamWrapper');
    stream_wrapper_register('gs', 'GSStreamWrapper');
    
    // Use it!
    
Caveats
-------

Streams reads, but unfortunately buffers writes (using a file), since S3 does not support chunked PUTs and we must know the size in advance. On the plus side, this way we can calculate the MD5 hash, which must also be known at the beginning of an upload.

Writes on flush or close (use flush to get a return value). Returns an error if `write()`'ing after a `flush()`, to help you accidentally writing scripts that would do multiple uploads.

Does not behave quite like a normal filesystem:   

  - You can write to any file, and parent directories will be created automatically.                                                      
  - You can open any directory, even if it doesn't exist. It will be empty.

Multipart upload is not supported and I have no plans of adding it myself.

License (MIT)
-------------

    Copyright (c) 2011 Jaka Jancar <jaka@kubje.org>

    Permission is hereby granted, free of charge, to any person obtaining a copy
    of this software and associated documentation files (the "Software"), to deal
    in the Software without restriction, including without limitation the rights
    to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
    copies of the Software, and to permit persons to whom the Software is
    furnished to do so, subject to the following conditions:

    The above copyright notice and this permission notice shall be included in
    all copies or substantial portions of the Software.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
    IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
    FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
    AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
    LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
    OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
    THE SOFTWARE.
