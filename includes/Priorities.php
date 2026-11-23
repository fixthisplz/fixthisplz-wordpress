<?php

namespace FixThisPlz;

defined( 'ABSPATH' ) || exit;

/** Report priorities: ordered list from the "priorities" setting, lowest first (sorting by priority follows it). */
final class Priorities extends Choice_List {

	public const SETTING = 'priorities';

	public const FIELD = 'priority';

	/** Every new report is `normal`. */
	public const REQUIRED = array( 'normal' );

	public const MAX = 10;

	protected static function default_labels(): array {
		return array(
			'low'      => __( 'Low', 'fixthisplz' ),
			'normal'   => __( 'Normal', 'fixthisplz' ),
			'high'     => __( 'High', 'fixthisplz' ),
			'critical' => __( 'Critical', 'fixthisplz' ),
		);
	}
}
