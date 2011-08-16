<?php

class GSStreamWrapper extends S3StreamWrapper
{
    protected function getHost()
    {
        return 'commondatastorage.googleapis.com';
    }
}
