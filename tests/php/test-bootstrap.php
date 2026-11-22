<?php

bugspot_test( 'plugin_is_loaded', function () {
	assert_true( class_exists( 'Bug_Spotter\\Plugin' ) );
	assert_same( '1.2.0', BUGSPOT_VERSION );
} );
