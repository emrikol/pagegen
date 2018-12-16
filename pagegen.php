<?php
/**
 * Plugin Name: Pagegen
 * Description: Generates a graph in the WordPress adming showing historic page generation times.
 * Version: 1.0
 * Author: Derrick Tennant
 * Author URI: https://emrikol.com/
 * GitHub Plugin URI: https://github.com/emrikol/pagegen
 *
 * @package WordPress
 */
require __DIR__ . '/inc/class-pagegen.php';
Pagegen::get_instance()->init_hooks();
