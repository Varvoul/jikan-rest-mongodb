<?php
/**
 * PHP 8.1+ function polyfills for PHP 8.0 compatibility
 * Loaded via auto_prepend_file in PHP built-in server
 */

if (!function_exists('array_is_list')) {
    /**
     * Checks whether a given array is a list.
     * An array is considered a list if its keys consist of consecutive numbers from 0.
     */
    function array_is_list(array $arr): bool {
        if (empty($arr)) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}