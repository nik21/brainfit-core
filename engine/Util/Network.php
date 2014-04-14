<?php
namespace Brainfit\Util;

use Brainfit\Model\Exception;
use Brainfit\Settings;

class Network
{
    /**
     * Gets the original ip-address processed in accordance with the mask
     *
     * @return string
     *
     * @param $sIpAddress - full IP-address
     * @param $sMask - Subnet mask. Can be expressed as 255.255.255.0 or "C" or "24"
     *
     * @throws Exception
     * @return string
     */
    public static function applyNetMask($sIpAddress, $sMask)
    {
        $iIp = ip2long($sIpAddress);
        $iMask = ip2long($sMask);

        if(!$iIp || !$iMask)
            throw new Exception('Wrong format ip-address');

        return long2ip(sprintf('%u', $iIp & $iMask));
    }

    /**
     * Whether the address to the list of servers in the cluster, you can trust him?
     * @param $ip
     *
     * @throws Exception
     * @return bool
     */
    public static function isTrustInternalAddress($ip)
    {
        $aNetworks = (array)Settings::get('PROJECT', 'INTERNAL_NETWORKS');
        if(!$aNetworks)
            throw new Exception('Not specified for the project INTERNAL_NETWORKS');

        foreach($aNetworks as $sNetwork)
        {
            if(!$sNetwork)
                continue;

            if(mb_substr($ip, 0, mb_strlen($sNetwork)) === $sNetwork)
                return true;
        }

        return false;
    }
}