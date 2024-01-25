<?php

namespace Banzai\Authentication\login;

interface NotificationInterface
{

    function __construct($db = null, $logger = null);

    public function send($userdata = array());
}


