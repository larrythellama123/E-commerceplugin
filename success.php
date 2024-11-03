<?php
/**
 * Template Name: Stripe Success Page
 * Description: A template for displaying the Stripe checkout success message.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

get_header(); ?>

<section>
    <p>
        We appreciate your business! If you have any questions, please email
        <a href="mailto:orders@example.com">orders@example.com</a>.
    </p>
</section>

<?php get_footer(); ?>
