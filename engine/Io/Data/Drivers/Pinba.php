<?php
namespace Io\Data\Drivers;

/**
 * see http://pinba.org/wiki/Manual:PHP_extension#pinba_timer_start.28.29
 */
class Pinba
{
    public static function timer_start($tags)
    {
        if(!\Server::PINBA_ENABLED)
            return false;

        return pinba_timer_start($tags);
    }

    public static function timer_stop($obInstance)
    {
        if($obInstance === false || !\Server::PINBA_ENABLED)
            return false;

        pinba_timer_stop($obInstance);
    }

    /*pinba_timer_delete()
    pinba_timer_tags_merge()
    pinba_timer_tags_replace()
    pinba_timer_data_merge()
    pinba_timer_data_replace()
    pinba_timer_get_info()
    pinba_timers_stop()
    pinba_get_info()
    pinba_script_name_set()
    pinba_hostname_set()
    pinba_flush()*/
}
