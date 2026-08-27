<?php

/**
 * Build the synthetic Northwind Skin Studio site used for Atlas integration tests.
 *
 * Run with:
 * docker-compose -f infrastructure/testing/northwind-wordpress/compose.yml run --rm cli \
 *   wp eval-file /opt/northwind-site/bootstrap.php
 */

if (! defined('ABSPATH')) {
    exit(1);
}

function northwind_upsert_page(string $title, string $slug, string $content): int
{
    $existing = get_page_by_path($slug, OBJECT, 'page');
    $post = [
        'ID' => $existing?->ID ?? 0,
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => $title,
        'post_name' => $slug,
        'post_content' => $content,
    ];

    $id = wp_insert_post(wp_slash($post), true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }

    return (int) $id;
}

$home = northwind_upsert_page('Home', 'home', <<<'HTML'
<!-- wp:group {"style":{"spacing":{"padding":{"top":"72px","bottom":"72px","left":"32px","right":"32px"}},"color":{"background":"#f4eee8"}},"layout":{"type":"constrained","contentSize":"1120px"}} -->
<div class="wp-block-group has-background" style="background-color:#f4eee8;padding-top:72px;padding-right:32px;padding-bottom:72px;padding-left:32px"><!-- wp:paragraph {"style":{"typography":{"letterSpacing":"0.12em","textTransform":"uppercase"},"color":{"text":"#7a5d4e"}}} --><p style="color:#7a5d4e;letter-spacing:0.12em;text-transform:uppercase">Thoughtful skincare in Scottsdale</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"64px","lineHeight":"1.05"},"color":{"text":"#2d2926"}}} --><h1 class="wp-block-heading" style="color:#2d2926;font-size:64px;line-height:1.05">Healthy skin. Quiet confidence.</h1><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"21px","lineHeight":"1.6"},"color":{"text":"#5d5651"}},"fontSize":"medium"} --><p class="has-medium-font-size" style="color:#5d5651;font-size:21px;line-height:1.6">Northwind Skin Studio pairs evidence-informed treatments with a calm, personal approach. Every plan begins with listening and ends with care that feels like you.</p><!-- /wp:paragraph -->
<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"style":{"color":{"background":"#6f4f42","text":"#ffffff"},"border":{"radius":"4px"}}} --><div class="wp-block-button"><a class="wp-block-button__link has-text-color has-background wp-element-button" href="/book-a-consultation/" style="border-radius:4px;color:#ffffff;background-color:#6f4f42">Plan your visit</a></div><!-- /wp:button --><!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/services/">Explore services</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:group -->
<!-- wp:group {"style":{"spacing":{"padding":{"top":"56px","bottom":"56px"}}},"layout":{"type":"constrained","contentSize":"1120px"}} --><div class="wp-block-group" style="padding-top:56px;padding-bottom:56px"><!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">Care built around your skin</h2><!-- /wp:heading --><!-- wp:columns {"style":{"spacing":{"blockGap":{"left":"32px"}}}} --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Custom facials</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Restorative treatments tailored to hydration, texture, clarity, and barrier health.</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Skin renewal</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Thoughtful microneedling and renewal plans designed around comfort and realistic goals.</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Personal consultations</h3><!-- /wp:heading --><!-- wp:paragraph --><p>A relaxed conversation about your routine, priorities, and the options that fit your timeline.</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->
<!-- wp:group {"style":{"spacing":{"padding":{"top":"56px","bottom":"56px","left":"32px","right":"32px"}},"color":{"background":"#263c36","text":"#ffffff"}},"layout":{"type":"constrained","contentSize":"900px"}} --><div class="wp-block-group has-text-color has-background" style="color:#ffffff;background-color:#263c36;padding-top:56px;padding-right:32px;padding-bottom:56px;padding-left:32px"><!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">Simple recommendations. Natural-looking results.</h2><!-- /wp:heading --><!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center">We believe good care should feel clear, unrushed, and grounded in education—not pressure.</p><!-- /wp:paragraph --></div><!-- /wp:group -->
<!-- wp:paragraph {"align":"center","style":{"spacing":{"margin":{"top":"24px","bottom":"24px"}},"typography":{"fontSize":"13px"},"color":{"text":"#746b66"}}} --><p class="has-text-align-center" style="color:#746b66;margin-top:24px;margin-bottom:24px;font-size:13px"><strong>Atlas integration test site.</strong> Northwind Skin Studio is fictional and does not accept appointments.</p><!-- /wp:paragraph -->
HTML);

$about = northwind_upsert_page('About', 'about', <<<'HTML'
<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Care that begins with listening</h1><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"21px","lineHeight":"1.6"}}} --><p style="font-size:21px;line-height:1.6">Northwind Skin Studio is a fictional Scottsdale skincare studio created to test Atlas marketing integrations. Its brand is built around calm guidance, transparent treatment planning, and long-term skin health.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2 class="wp-block-heading">Our approach</h2><!-- /wp:heading --><ul><li>Start with your goals and current routine.</li><li>Explain options in plain language.</li><li>Recommend only what fits your comfort and timeline.</li><li>Favor subtle, sustainable results over quick fixes.</li></ul>
<!-- wp:quote --><blockquote class="wp-block-quote"><p>Confidence grows when care feels personal, informed, and unhurried.</p></blockquote><!-- /wp:quote -->
<!-- wp:paragraph --><p><em>This is a synthetic business profile. No medical services or appointments are provided.</em></p><!-- /wp:paragraph -->
HTML);

$services = northwind_upsert_page('Services', 'services', <<<'HTML'
<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Services designed around you</h1><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"21px"}}} --><p style="font-size:21px">Every Northwind plan begins with a conversation about your skin, comfort, and goals.</p><!-- /wp:paragraph -->
<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->
<!-- wp:heading --><h2 class="wp-block-heading">Custom facial · 60 minutes</h2><!-- /wp:heading --><p>A personalized treatment focused on hydration, clarity, texture, and barrier support.</p>
<!-- wp:heading --><h2 class="wp-block-heading">Skin consultation · 30 minutes</h2><!-- /wp:heading --><p>A one-to-one review of your routine, concerns, and a practical path forward.</p>
<!-- wp:heading --><h2 class="wp-block-heading">Microneedling consultation</h2><!-- /wp:heading --><p>Education-first planning for texture and tone, including candid discussion of suitability and downtime.</p>
<!-- wp:heading --><h2 class="wp-block-heading">Seasonal skin plan</h2><!-- /wp:heading --><p>A simple quarterly plan that adapts your home care and studio treatments to changing conditions.</p>
<!-- wp:paragraph --><p><strong>Demo environment:</strong> These services are fictional and cannot be booked.</p><!-- /wp:paragraph -->
HTML);

$consultation = northwind_upsert_page('Consultation', 'book-a-consultation', <<<'HTML'
<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Start with a conversation</h1><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"21px","lineHeight":"1.6"}}} --><p style="font-size:21px;line-height:1.6">A Northwind consultation would normally be a relaxed, 30-minute conversation about your skin, routine, and priorities.</p><!-- /wp:paragraph -->
<!-- wp:group {"style":{"color":{"background":"#f4eee8"},"spacing":{"padding":{"top":"32px","right":"32px","bottom":"32px","left":"32px"}}}} --><div class="wp-block-group has-background" style="background-color:#f4eee8;padding:32px"><!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Test environment only</h2><!-- /wp:heading --><p>Northwind Skin Studio is a fictional business used for Atlas integration testing. No appointments are accepted and this page does not collect personal information.</p></div><!-- /wp:group -->
HTML);

$blog = northwind_upsert_page('Blog', 'blog', '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">The Northwind Journal</h1><!-- /wp:heading -->');

$contact = northwind_upsert_page('Contact', 'contact', <<<'HTML'
<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Contact Northwind</h1><!-- /wp:heading --><p>Northwind Skin Studio is a fictional business maintained as an Atlas integration fixture. It has no physical location, phone number, or customer inbox.</p><p>For test validation, use the Atlas project workflow rather than submitting personal information here.</p>
HTML);

update_option('blogname', 'Northwind Skin Studio');
update_option('blogdescription', 'Thoughtful skincare for quiet confidence');
update_option('show_on_front', 'page');
update_option('page_on_front', $home);
update_option('page_for_posts', $blog);
update_option('permalink_structure', '/%postname%/');

$sample = get_page_by_path('sample-page', OBJECT, 'page');
if ($sample) {
    wp_update_post(['ID' => $sample->ID, 'post_status' => 'draft']);
}
$legacyHome = get_page_by_path('northwind-skin-studio', OBJECT, 'page');
if ($legacyHome) {
    wp_update_post(['ID' => $legacyHome->ID, 'post_status' => 'draft']);
}

$navigation = get_posts([
    'post_type' => 'wp_navigation',
    'post_status' => 'publish',
    'numberposts' => 1,
])[0] ?? null;

if ($navigation) {
    $links = [
        [$home, 'Home'],
        [$about, 'About'],
        [$services, 'Services'],
        [$blog, 'Blog'],
        [$consultation, 'Consultation'],
        [$contact, 'Contact'],
    ];
    $content = implode('', array_map(
        static fn (array $link): string => sprintf(
            '<!-- wp:navigation-link {"label":"%s","type":"page","id":%d,"url":"%s","kind":"post-type"} /-->',
            esc_attr($link[1]),
            $link[0],
            esc_url(get_permalink($link[0]))
        ),
        $links
    ));
    wp_update_post(['ID' => $navigation->ID, 'post_content' => wp_slash($content)]);
}

function northwind_upsert_theme_entity(string $type, string $slug, string $title, string $content): int
{
    $existing = get_page_by_path($slug, OBJECT, $type);
    $id = wp_insert_post(wp_slash([
        'ID' => $existing?->ID ?? 0,
        'post_type' => $type,
        'post_status' => 'publish',
        'post_name' => $slug,
        'post_title' => $title,
        'post_content' => $content,
    ]), true);

    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }

    wp_set_object_terms((int) $id, 'twentytwentyfive', 'wp_theme');

    return (int) $id;
}

$footer = northwind_upsert_theme_entity('wp_template_part', 'footer', 'Northwind Footer', sprintf(
    '<!-- wp:group {"style":{"spacing":{"padding":{"top":"40px","bottom":"40px"}},"border":{"top":{"color":"#d7cec7","width":"1px"}}},"layout":{"type":"constrained","contentSize":"1120px"}} --><div class="wp-block-group" style="border-top-color:#d7cec7;border-top-width:1px;padding-top:40px;padding-bottom:40px"><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:site-title {"level":3} /--><!-- wp:site-tagline /--></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:navigation {"ref":%d,"overlayMenu":"never","layout":{"type":"flex","justifyContent":"right"}} /--></div><!-- /wp:column --></div><!-- /wp:columns --><!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"12px"},"color":{"text":"#746b66"}}} --><p class="has-text-align-center" style="color:#746b66;font-size:12px">Synthetic Atlas integration site · No real services or appointments</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
    $navigation?->ID ?? 0
));
wp_set_object_terms($footer, 'footer', 'wp_template_part_area');

northwind_upsert_theme_entity('wp_template', 'front-page', 'Northwind Front Page',
    '<!-- wp:template-part {"slug":"header","theme":"twentytwentyfive","tagName":"header"} /--><!-- wp:post-content {"layout":{"type":"constrained"}} /--><!-- wp:template-part {"slug":"footer","theme":"twentytwentyfive","tagName":"footer"} /-->'
);

flush_rewrite_rules();

WP_CLI::success('Northwind site content and navigation updated.');
