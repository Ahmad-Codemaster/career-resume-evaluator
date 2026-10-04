# Career Resume Evaluator (WordPress Plugin)

Career Resume Evaluator is a WordPress plugin that generates an AI-powered career roadmap from a candidate’s resume and preferences. It analyzes resume content, suggests role paths, highlights skill gaps, and optionally gates full report access behind a Stripe payment.

## Features

- Frontend career evaluation form via shortcode: `[career_evaluator]`
- Resume upload and parsing (DOCX required on the form; parser includes PDF support)
- Two-step Grok AI analysis pipeline:
  - Resume grounding/classification
  - Structured career roadmap generation
- Optional Adzuna job matching for suggested roles
- Optional Stripe payment unlock flow for full report access
- Magic-link email delivery for report recovery
- Admin settings page for API keys, model selection, and payment toggle
- Submission history and CSV export from WordPress admin

## Requirements

- WordPress site with plugin install access
- PHP environment compatible with WordPress
- API credentials as needed:
  - xAI (Grok) API key
  - Adzuna App ID + App Key (optional)
  - Stripe Publishable + Secret keys (optional, when payments are enabled)

## Installation

1. Copy this plugin folder into your WordPress `wp-content/plugins/` directory.
2. Activate **LumenPath Career Planner** from the WordPress Plugins screen.
3. On activation, the plugin creates a submissions table in your WordPress database.

## Configuration

Go to **Settings → Career Evaluator** in WordPress admin and configure:

- **Grok API Key**
- **AI Model** (select from available Grok models)
- **Enable Payments** (`yes/no` behavior via checkbox)
- **Stripe Publishable/Secret Keys** (required only if payments are enabled)
- **Adzuna App ID/App Key** (optional for job listings)

Use the **Test API Connection** button to validate Grok connectivity.

## Usage

1. Add the shortcode below to any page/post:

   ```text
   [career_evaluator]
   ```

2. Users complete the form and upload a resume.
3. The plugin generates and renders a personalized roadmap.
4. If payments are enabled, users see a teaser and can pay to unlock full report details.
5. Users can revisit the report using the generated magic link (`?cre_report=<session_id>`).

## Data & Storage

- Submissions are stored in a custom table: `{$wpdb->prefix}cre_submissions`
- Includes candidate metadata, input payload, AI result payload, and Adzuna payload
- Temporary per-report session data is stored in WordPress options under:
  - `cre_report_<uuid>`

## Main Components

- `career-resume-evaluator.php` – Plugin bootstrap, autoloading, activation hook
- `includes/class-settings.php` – Admin settings UI, API test, CSV export, history tab
- `includes/class-shortcode.php` – Shortcode registration and form rendering
- `includes/class-ajax.php` – AJAX handlers, AI calls, payment flow, report rendering, emails
- `includes/class-file-parser.php` – Resume text extraction helpers
- `includes/class-db.php` – Database table creation and submission CRUD helpers
- `assets/js/script.js` – Frontend flow (submit, restore, payment, email)
- `assets/js/admin.js` – Admin API connection test interaction
- `assets/css/style.css` – Frontend styling

## Notes

- Frontend upload currently validates `.docx` files.
- Ensure outgoing email is configured on your WordPress host for magic-link delivery.
- Keep API keys in plugin settings only; never hardcode secrets in source.
