<?php

defined( 'ABSPATH' ) || exit;

class WTI_Scheduler {
	const SCHEDULE_FOUR_HOURS = 'wti_four_hours';
	const SCHEDULE_SIX_HOURS  = 'wti_six_hours';

	public static function add_cron_schedules( $schedules ) {
		$schedules[ self::SCHEDULE_FOUR_HOURS ] = array(
			'interval' => 4 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 4 hours', WTI_TEXT_DOMAIN ),
		);

		$schedules[ self::SCHEDULE_SIX_HOURS ] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 hours', WTI_TEXT_DOMAIN ),
		);

		return $schedules;
	}

	public static function activate() {
		$settings = WTI_Admin::get_settings();

		if ( ! wp_next_scheduled( WTI_CRON_HOOK ) ) {
			wp_schedule_event( self::next_run_timestamp( $settings ), self::get_recurrence( $settings ), WTI_CRON_HOOK );
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
		$time = isset( $settings['sync_time'] ) ? $settings['sync_time'] : '17:00';

		if ( ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			$time = '17:00';
		}

		$timezone = wp_timezone();
		$now      = new DateTimeImmutable( 'now', $timezone );
		$next     = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $now->format( 'Y-m-d' ) . ' ' . $time . ':00', $timezone );

		if ( ! $next ) {
			return $now->modify( '+1 hour' )->getTimestamp();
		}

		$recurrence = self::get_recurrence( $settings );

		if ( 'daily' === $recurrence ) {
			if ( $next <= $now ) {
				$next = $next->modify( '+1 day' );
			}

			return $next->getTimestamp();
		}

		$step = self::SCHEDULE_SIX_HOURS === $recurrence ? '+6 hours' : '+4 hours';

		while ( $next <= $now ) {
			$next = $next->modify( $step );
		}

		return $next->getTimestamp();
	}

	private static function get_recurrence( $settings ) {
		$interval = isset( $settings['sync_interval'] ) ? $settings['sync_interval'] : self::SCHEDULE_FOUR_HOURS;
		$allowed  = array( self::SCHEDULE_FOUR_HOURS, self::SCHEDULE_SIX_HOURS, 'daily' );

		return in_array( $interval, $allowed, true ) ? $interval : self::SCHEDULE_FOUR_HOURS;
	}
}
