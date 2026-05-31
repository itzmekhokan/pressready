<?php
// Deprecated usages (should be flagged):
$data = get_postdata( 42 );                 // function, dep 1.5.1
echo readonly( 'a', 'b' );                   // function, dep 5.9.0 (T_READONLY keyword)
$u = new WP_User_Search();                   // class, dep 3.1.0 (_deprecated_class)
$j = Services_JSON::decode( $str );          // method, dep 5.3.0
add_filter( 'query_string', 'cb' );          // hook (filter), dep 2.1.0
do_action( 'add_tag_form' );                 // hook (action), dep 3.0.0

// NOT deprecated (must NOT be flagged):
$p = get_post( 42 );
add_filter( 'the_content', 'cb' );
$x = new WP_Query( array() );
$obj->get_postdata();                        // method call on object, not the core fn
add_filter( $dynamic_hook, 'cb' );           // dynamic hook name
$f = new POMO_FileReader( $file );           // only the PHP4 constructor is deprecated,
                                             // the class itself is fine — must NOT flag
