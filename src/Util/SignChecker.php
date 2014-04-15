<?php
namespace Brainfit\Util;

use Brainfit\Model\Exception;
use Brainfit\Settings;

class SignChecker
{
    const SALT = 'yb84h30-hvd9vf9h';

    public static function createSign($sData)
    {
        $sPrivateKeyFilename = Settings::get('ENCRYPTION', 'privateKey');
        if(!$sPrivateKeyFilename || !file_exists(ROOT.$sPrivateKeyFilename))
            throw new Exception('In the configuration file is not specified with a private key');

        $sPrivateKey = file_get_contents(ROOT.$sPrivateKeyFilename);
        $rPrivateKeyId = openssl_get_privatekey($sPrivateKey);
        $sPrivateKey = str_repeat('0', 8192);

        if(!$rPrivateKeyId || !self::SALT)
            throw new Exception('Unable to sign data');

        //Подписываем данные $sItemKey:
        openssl_sign(self::SALT.$sData, $signature, $rPrivateKeyId);
        openssl_free_key($rPrivateKeyId);

        if(!$signature)
            throw new Exception('Unable to sign data');

        return base64_encode($signature);
    }

    public static function verifySign($sData, $sSign)
    {
        $sPublicKeyFilename = Settings::get('ENCRYPTION', 'publicKey');
        if(!$sPublicKeyFilename || !file_exists(ROOT.$sPublicKeyFilename))
            throw new Exception('In the configuration file is not specified with the public key');

        $sPublicKey = file_get_contents(ROOT.$sPublicKeyFilename);
        $rPublicKeyId = openssl_get_publickey($sPublicKey);

        if(!$rPublicKeyId || !self::SALT)
            throw new Exception('Unable to verify the signature of the data');

        //Проверяем данные
        $iResult = openssl_verify(self::SALT.$sData, base64_decode($sSign), $rPublicKeyId);

        openssl_free_key($rPublicKeyId);

        if($iResult === 1)
            return true;

        return false;
    }
}