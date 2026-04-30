function check_status(ajax_url) {
    let is_paid = false;
    let startedAt = Date.now();
    let lastInterval = 2000;

    function status_loop() {
        if (is_paid) return;

        jQuery.getJSON(ajax_url, function (data) {
            if (!data) return;

            let waiting_payment = jQuery('.waiting_payment');
            let waiting_network = jQuery('.waiting_network');
            let payment_done = jQuery('.payment_done');

            if (data.remaining !== undefined) {
                jQuery('.ca_value').text(data.remaining);
            }
            if (data.fiat_remaining !== undefined) {
                jQuery('.ca_fiat_total').text(data.fiat_remaining);
            }
            if (data.remaining !== undefined) {
                jQuery('.ca_copy.ca_details_copy').attr('data-tocopy', data.remaining);
            }

            if (data.cancelled === 1) {
                jQuery('.ca_loader').remove();
                jQuery('.ca_payments_wrapper').slideUp('200');
                jQuery('.ca_payment_cancelled').slideDown('200');
                jQuery('.ca_progress').slideUp('200');
                is_paid = true;
            }

            if (data.is_pending === 1) {
                waiting_payment.addClass('done');
                waiting_network.addClass('done');
                jQuery('.ca_loader').remove();
                jQuery('.ca_notification_refresh').remove();
                jQuery('.ca_notification_cancel').remove();

                setTimeout(function () {
                    jQuery('.ca_payments_wrapper').slideUp('200');
                    jQuery('.ca_payment_processing').slideDown('200');
                }, 300);
            }

            if (data.is_paid) {
                waiting_payment.addClass('done');
                waiting_network.addClass('done');
                payment_done.addClass('done');
                jQuery('.ca_loader').remove();
                jQuery('.ca_notification_refresh').remove();
                jQuery('.ca_notification_cancel').remove();

                setTimeout(function () {
                    jQuery('.ca_payments_wrapper').slideUp('200');
                    jQuery('.ca_payment_processing').slideUp('200');
                    jQuery('.ca_payment_confirmed').slideDown('200');
                }, 300);

                is_paid = true;
            }

            // Server-guided polling interval (ms). Falls back to adaptive client logic.
            if (data.poll_interval_ms && !isNaN(parseInt(data.poll_interval_ms, 10))) {
                lastInterval = Math.max(2000, Math.min(30000, parseInt(data.poll_interval_ms, 10)));
            } else {
                // Adaptive backoff to reduce requests:
                // 0–30s: 2s, 30–120s: 5s, >120s: 10s (cap 30s)
                const elapsed = (Date.now() - startedAt) / 1000;
                if (elapsed > 120) lastInterval = 10000;
                else if (elapsed > 30) lastInterval = 5000;
                else lastInterval = 2000;
            }

            if (data.qr_code_value) {
                jQuery('.ca_qrcode.value').attr("src", "data:image/png;base64," + data.qr_code_value);
            }

            if (data.show_min_fee === 1) {
                jQuery('.ca_notification_remaining').show();
            } else {
                jQuery('.ca_notification_remaining').hide();
            }

            if (data.hide_refresh === 1) {
                jQuery('.ca_time_refresh').hide();
            } else {
                jQuery('.ca_time_refresh').show();
            }

            if (data.remaining !== undefined && data.crypto_total !== undefined && data.remaining !== data.crypto_total) {
                jQuery('.ca_notification_payment_received').show();
                jQuery('.ca_notification_cancel').remove();
                if (data.already_paid !== undefined && data.coin !== undefined && data.already_paid_fiat !== undefined && data.fiat_symbol !== undefined) {
                    let amount_html = jQuery('<span>').text(data.already_paid + ' ' + data.coin + ' (').add(jQuery('<strong>').text(data.already_paid_fiat + ' ' + data.fiat_symbol)).add(jQuery('<span>').text(')'));
                    jQuery('.ca_notification_ammount').empty().append(amount_html);
                }
            }

            if (data.order_history && typeof data.order_history === 'object') {
                let history = data.order_history;

                if (jQuery('.ca_history_fill tr').length < Object.entries(history).length + 1) {
                    jQuery('.ca_history').show();

                    jQuery('.ca_history_fill td:not(.ca_history_header)').remove();

                    Object.entries(history).forEach(([key, value]) => {
                        if (value && value.timestamp) {
                            let time = new Date(value.timestamp * 1000).toLocaleTimeString(document.documentElement.lang);
                            let date = new Date(value.timestamp * 1000).toLocaleDateString(document.documentElement.lang);

                            let row = jQuery('<tr>');
                            let td1 = jQuery('<td>').text(time + ' ').append(jQuery('<span>').addClass('ca_history_date').text(date));
                            let td2 = jQuery('<td>').text((value.value_paid || '0') + ' ' + (data.coin || ''));
                            let td3 = jQuery('<td>').append(jQuery('<strong>').text((value.value_paid_fiat || '0') + ' ' + (data.fiat_symbol || '')));
                            
                            row.append(td1).append(td2).append(td3);
                            jQuery('.ca_history_fill').append(row);
                        }
                    });
                }
            }

            if (jQuery('.ca_time_refresh')[0]) {
                var timer = jQuery('.ca_time_seconds_count');

                if (timer.length && timer.attr('data-seconds') <= 0 && data.counter !== undefined) {
                    timer.attr('data-seconds', data.counter);
                }
            }
        });

        setTimeout(status_loop, lastInterval);
    }

    status_loop();
}

function copyToClipboard(text) {
    if (window.clipboardData && window.clipboardData.setData) {
        return clipboardData.setData("Text", text);

    } else if (document.queryCommandSupported && document.queryCommandSupported("copy")) {
        var textarea = document.createElement("textarea");
        textarea.textContent = text;
        textarea.style.position = "fixed";
        document.body.appendChild(textarea);
        textarea.select();
        try {
            return document.execCommand("copy");
        } catch (ex) {
            console.warn("Copy to clipboard failed.", ex);
            return false;
        } finally {
            document.body.removeChild(textarea);
        }
    }
}

jQuery(function ($) {

    if ($('.ca_time_refresh')[0] || $('.ca_notification_cancel')[0]) {
        setInterval(function () {

            if ($('.ca_time_refresh')[0]) {
                var refresh_time_span = $('.ca_time_seconds_count'),
                    refresh_time = refresh_time_span.attr('data-seconds') - 1;

                if (refresh_time <= 0) {
                    refresh_time_span.html('00:00');
                    refresh_time_span.attr('data-seconds', 0);
                    return;
                } else if (refresh_time <= 30) {
                    refresh_time_span.html(refresh_time_span.attr('data-soon'));
                }

                var refresh_minutes = Math.floor(refresh_time % 3600 / 60).toString().padStart(2, '0'),
                    refresh_seconds = Math.floor(refresh_time % 60).toString().padStart(2, '0');

                refresh_time_span.html(refresh_minutes + ':' + refresh_seconds);

                refresh_time_span.attr('data-seconds', refresh_time);
            }

            var ca_notification_cancel = $('.ca_notification_cancel');

            if (ca_notification_cancel[0]) {
                var cancel_time_span = $('.ca_cancel_timer'),
                    cancel_time = cancel_time_span.attr('data-timestamp') - 1;

                if (cancel_time <= 0) {
                    cancel_time_span.attr('data-timestamp', 0);
                    return;
                }

                var cancel_hours = Math.floor(cancel_time / 3600).toString().padStart(2, '0'),
                    cancel_minutes = Math.floor(cancel_time % 3600 / 60).toString().padStart(2, '0');

                if (cancel_time <= 60) {
                    ca_notification_cancel.html('<strong>' + ca_notification_cancel.attr('data-text') + '</strong>');
                } else {
                    cancel_time_span.html(cancel_hours + ':' + cancel_minutes);

                }
                cancel_time_span.attr('data-timestamp', cancel_time);
            }
        }, 1000);
    }


    $('.ca_qrcode_btn').on('click', function () {
        $('.ca_qrcode_btn').removeClass('active')
        $(this).addClass('active');

        if ($(this).hasClass('no_value')) {
            $('.ca_qrcode.no_value').show();
            $('.ca_qrcode.value').hide();
        } else {
            $('.ca_qrcode.value').show();
            $('.ca_qrcode.no_value').hide();
        }
    });

    $('.ca_show_qr').on('click', function (e) {
        e.preventDefault();

        let qr_code_close_text = $('.ca_show_qr_close');
        let qr_code_open_text = $('.ca_show_qr_open');

        if ($(this).hasClass('active')) {
            $('.ca_qrcode_wrapper').slideToggle(500);
            $(this).removeClass('active');
            qr_code_close_text.addClass('active');
            qr_code_open_text.removeClass('active');

        } else {
            $('.ca_qrcode_wrapper').slideToggle(500);
            $(this).addClass('active');
            qr_code_close_text.removeClass('active');
            qr_code_open_text.addClass('active');
        }
    });

    $('.ca_copy').on('click', function () {
        copyToClipboard($(this).attr('data-tocopy'));
        let tip = $(this).find('.ca_tooltip.tip');
        let success = $(this).find('.ca_tooltip.success');

        success.show();
        tip.hide();

        setTimeout(function () {
            success.hide();
            tip.show();
        }, 5000);
    })
})


