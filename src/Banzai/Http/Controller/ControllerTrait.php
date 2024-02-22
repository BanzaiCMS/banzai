<?php
declare(strict_types=1);

namespace Banzai\Http\Controller;

trait ControllerTrait
{

    protected function trimHeader(?string $line = null): string
    {
        if (empty($line))
            return '';

        $line = str_replace("\r\n", ' ', $line);
        $line = str_replace("\n", ' ', $line);
        $line = str_replace("\r", ' ', $line);
        $line = trim($line);

        return $line;
    }

    protected function getSecurityHeaders(): array
    {
        $ret = array();

        $csp = $this->trimHeader($this->params->get('system.security.http.csp'));
        $hsts = $this->trimHeader($this->params->get('system.security.http.hsts'));
        $cto = $this->trimHeader($this->params->get('system.security.http.cto'));
        $xssp = $this->trimHeader($this->params->get('system.security.http.xssp'));
        $xfo = $this->trimHeader($this->params->get('system.security.http.xfo'));
        $pp = $this->trimHeader($this->params->get('system.security.http.pp'));
        $ref = $this->trimHeader($this->params->get('system.security.http.referrer'));

        if (!empty($csp))
            $ret['Content-Security-Policy'] = $csp;

        // https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Feature-Policy
        if (!empty($pp)) {
            $ret['Feature-Policy'] = $pp;   // alter name
            $ret['Permissions-Policy'] = $pp;   // neuer name
        }

        if (!empty($hsts))
            $ret['Strict-Transport-Security'] = $hsts;

        if (!empty($cto))
            $ret['X-Content-Type-Options'] = $cto;

        if (!empty($xssp))
            $ret['X-XSS-Protection'] = $xssp;

        if (!empty($xfo))
            $ret['X-Frame-Options'] = $xfo;

        if (!empty($ref))
            $ret['Referrer-Policy'] = $ref;

        return $ret;

    }

}
