=== Lumen Path Career Roadmap ===
Contributors: Anus J
Tags: resume, career, ai, grok, adzuna, jobs
Requires at least: 6.0
Tested up to: 6.4
Stable tag: 1.1.12
License: GPLv2 or later

Know Your Next Career Move — With Data, Not Guesswork.

== Description ==

Answer a few questions about your future goals and work preferences so Lumen can map your skills into higher-paying, future-ready roles.

This plugin creates a frontend form for users to upload their resume (DOCX only) and input career goals. It extracts text from the resume, sends it along with user preferences to the xAI Grok API, and returns a detailed analysis table suggesting suitable career paths.

Additionally, it fetches live job listings from Adzuna based on the top recommended career path.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/career-resume-evaluator` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Settings -> Career Evaluator.
4. Enter your **Grok API Key**.
5. Enter your **Adzuna App ID** and **App Key** (required for job listings).
6. Use the shortcode `[career_evaluator]` on any page to display the form.

== Changelog ==

= 1.1.12 =
* Rebrand: Updated branding to 'Lumen Path Career Roadmap'.
* UI: Removed AI-Powered badge and updated header messaging for clarity.

= 1.1.11 =
* Design: Reduced overall font sizes for headings and body text for a refined, compact UI.
* Feature: Dynamic Currency Support. Salaries now display in the currency of the selected location (e.g., GBP for UK, INR for India).
* Feature: Added new "Missing Skills & Required Certifications" analysis section with deep-link course validation.
* Logic: Updated Career Table to display the "Entry Level (Immediate Start)" role in the primary column instead of the long-term target.

= 1.1.10 =
* Design: Major UI/UX overhaul to edge-to-edge layout.
* Animations: Added interactive hover states and animations to buttons and cards.
* Colors: Refined color palette to professional White/Black/Blue scheme.
* Responsive: Improved grid responsiveness for larger screens.
