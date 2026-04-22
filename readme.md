=== **Lumen Path Career Planner** ===

Contributors: Ahmad

Tags: resume, career, ai, grok, adzuna, jobs, stripe, monetization

Requires at least: 6.0

Tested up to: 6.4

Stable tag: 1.1.18

License: GPLv2 or later



Know Your Next Career Move — With Data, Not Guesswork.



== Description ==



Answer a few questions about your future goals and work preferences so Lumen can map your skills into higher-paying, future-ready roles.



This plugin creates a frontend form for users to upload their resume (DOCX only) and input career goals. It extracts text from the resume, applies strict deterministic logic through the xAI Grok API, and returns a highly realistic analysis table suggesting career paths.



It also features optional Stripe monetization, permanent report saving with magic email links, a comprehensive admin dashboard for lead generation, and live job fetching from Adzuna.



== Installation ==



1\. Upload the plugin files to the `/wp-content/plugins/career-resume-evaluator` directory, or install the plugin through the WordPress plugins screen directly.

2\. Activate the plugin through the 'Plugins' screen in WordPress.

3\. Go to Settings -> Career Evaluator.

4\. Enter your \*\*Grok API Key\*\* and select your AI model.

5\. (Optional) Enter your \*\*Stripe Publishable\*\* and \*\*Secret Keys\*\*, then check the box to require a $1.00 payment to unlock reports.

6\. Enter your \*\*Adzuna App ID\*\* and \*\*App Key\*\* (required for job listings).

7\. Use the shortcode `\[career\_evaluator]` on any page to display the form.



== Changelog ==



= 1.1.16 =

\* Security: Implemented "Fake Resume Kill-Switch" to automatically reject invalid, blank, or nonsense documents before generating a report.

\* Logic: Upgraded the AI decision engine with strict deterministic math scoring for resume strength, preventing inflated overlap scores.

\* Feature: Added global "Enable Payments" toggle in WP Admin to easily switch the tool between 100% free and paid ($1 unlock) modes without editing code.



= 1.1.15 =

\* Monetization: Integrated Stripe Payment Gateway to optionally lock the full report behind a paywall.

\* UI: Added a blurred "teaser" view for locked reports with a secure, on-page Stripe checkout element.



= 1.1.14 =

\* Feature: Added "Email Me This Report" functionality, allowing users to send themselves a secure magic link to their roadmap.

\* Database: Generated reports are now securely saved to the WordPress database as persistent sessions, allowing users to return to their results at any time.



= 1.1.13 =

\* Admin: Added "Submission History" tab in the WordPress dashboard to view all candidate data, resumes, and top recommended roles.

\* Admin: Added 1-click CSV Export functionality for lead generation and data management.

\* Notification: Added automatic email alerts to the site admin whenever a new candidate generates a roadmap.



= 1.1.12 =

\* Rebrand: Updated branding to 'Lumen Path Career Roadmap'.

\* UI: Removed AI-Powered badge and updated header messaging for clarity.



= 1.1.11 =

\* Design: Reduced overall font sizes for headings and body text for a refined, compact UI.

\* Feature: Dynamic Currency Support. Salaries now display in the currency of the selected location (e.g., GBP for UK, INR for India).

\* Feature: Added new "Missing Skills \& Required Certifications" analysis section with deep-link course validation.

\* Logic: Updated Career Table to display the "Entry Level (Immediate Start)" role in the primary column instead of the long-term target.



= 1.1.10 =

\* Design: Major UI/UX overhaul to edge-to-edge layout.

\* Animations: Added interactive hover states and animations to buttons and cards.

\* Colors: Refined color palette to professional White/Black/Blue scheme.

\* Responsive: Improved grid responsiveness for larger screens.

