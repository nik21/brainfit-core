<?php
namespace Brainfit\Util;

class Math
{
    public static function createID()
    {
        $sRet = '';

        for($cnt = 1; $cnt <= 32; $cnt++)
        {
            $sRet .= dechex(intval((mt_rand(0, 10000000) / 10000000) * 16));
        }

        return $sRet;
    }

    /**
     * 128 digit (a-f,0-9)
     *
     * @param string $optionalSalt
     * @return string
     */
    public static function createLongId($optionalSalt = '')
    {
        $sRand = self::createID().'+'.self::createID();

        return hash('sha512', md5($optionalSalt).'jb38xb2r'.$sRand.'be8h2bf'
            .$optionalSalt.'4e3lkwo8'.date('U').sha1($optionalSalt).implode('+', $_SERVER));
    }

    public static function otherDiffDate($end = '2020-06-09 10:30:00')
    {
        $dc = date_create($end);
        $dcn = date_create();

        if(!$dc)
            return false;

        $obInterval = date_diff($dcn, $dc);
        $out = @$obInterval->format("Years:%Y,Months:%M,Days:%d,Hours:%H,Minutes:%i,Seconds:%s");

        $a_out = array();
        $a = explode(',', $out);
        array_walk($a,
            function ($val, $key) use (&$a_out)
            {
                $v = explode(':', $val);
                $a_out[$v[0]] = $v[1];
            });

        return $a_out;
    }

    /**
     * Distance between two points
     * Prototype http://gis-lab.info/qa/great-circles.html
     *
     * @param $iPoint1Lat
     * @param $iPoint1Lng
     * @param $iPoint2Lat
     * @param $iPoint2Lng
     * @return float
     */
    public static function calculateTheDistance($iPoint1Lat, $iPoint1Lng, $iPoint2Lat, $iPoint2Lng)
    {
        $EARTH_RADIUS = 6372795;
        //convert coordinates to radians
        $lat1 = $iPoint1Lat * M_PI / 180;
        $lat2 = $iPoint2Lat * M_PI / 180;
        $long1 = $iPoint1Lng * M_PI / 180;
        $long2 = $iPoint2Lng * M_PI / 180;

        //cosines and sines of latitudes and longitudes difference
        $cl1 = cos($lat1);
        $cl2 = cos($lat2);
        $sl1 = sin($lat1);
        $sl2 = sin($lat2);
        $delta = $long2 - $long1;
        $cdelta = cos($delta);
        $sdelta = sin($delta);

        //calculating the length of a great circle
        $y = sqrt(pow($cl2 * $sdelta, 2) + pow($cl1 * $sl2 - $sl1 * $cl2 * $cdelta, 2));
        $x = $sl1 * $sl2 + $cl1 * $cl2 * $cdelta;

        $ad = atan2($y, $x);
        $dist = $ad * $EARTH_RADIUS;

        return $dist;
    }
}