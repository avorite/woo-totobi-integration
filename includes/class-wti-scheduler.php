<?php

defined( 'ABSPATH' ) || exit;

class WTI_Scheduler {
	public static function activate() {
		$settings = WTI_Admin::get_settings();

		if ( ! wp_next_scheduled( WTI_CRON_HOOK ) ) {
			wp_schedule_event( self::next_run_timestamp( $settings ), 'daily', WTI_CRON_HOOK );
		}
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( WTI_CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, WTI_CRON_HOOK );
		}
	}

	public static function reschedule() {
		self::deactivate();
		self::activate();
	}

	public static function next_run_timestamp( $settings ) {
		$time = isset( $settings['sync_time'] ) ? $settings['sync_time'] : '15:00';

		if ( ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			$time = '15:00';
		}

		$timezone = wp_timezone();
		$now      = new DateTimeImmutable( 'now', $timezone );
		$next     = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $now->format( 'Y-m-d' ) . ' ' . $time . ':00', $timezone );

		if ( ! $next || $next <= $now ) {
			$next = $next ? $next->modify( '+1 day' ) : $now->modify( '+1 day' );
		}

		return $next->getTimestamp();
	}
}

