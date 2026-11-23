<?php

fixthisplz_test( 'plugin_is_loaded', function () {
	assert_true( class_exists( 'FixThisPlz\\Plugin' ) );
	assert_same( '1.2.0', FIXTHISPLZ_VERSION );
} );
