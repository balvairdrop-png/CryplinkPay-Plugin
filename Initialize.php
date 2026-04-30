<?php

namespace CrypLink;

class Initialize {
    public function initialize()
    {
        add_action('init', [$this, 'schedule_cron_job']);
    }

    public function schedule_cron_job()
    {
        // NOTE: cryplink_interval (60s) is registered in CrypLink.php via cron_schedules filter.
        // Using 'hourly' here would conflict with that registration and the activation hook.
        // We intentionally do NOT reschedule here; the activation hook in CrypLink.php already
        // schedules the event on plugin activation. This method now only acts as a safety net
        // for sites that were active before the activation hook ran correctly.
        if (!wp_next_scheduled('cryplink_cronjob')) {
            wp_schedule_event(time(), 'cryplink_interval', 'cryplink_cronjob');
        }
    }
}




