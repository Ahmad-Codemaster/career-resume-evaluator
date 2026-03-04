# Career Resume Evaluator

A WordPress plugin that creates a frontend form for users to upload their resume (DOCX only) and input career goals. It extracts text from the resume, sends it along with user preferences to the [xAI Grok API](https://console.x.ai/), and returns a detailed analysis table suggesting suitable career paths.

---

## Features

- **DOCX resume upload** – accepts `.docx` files only (parsed server-side with PHP's `ZipArchive`).
- **Career goals input** – free-text textarea for the user to describe desired roles and industries.
- **xAI Grok API integration** – sends resume text + career goals to the Grok model and receives structured career suggestions.
- **Results table** – displays career paths with match score, required skills, skills to develop, and a personalised recommendation.
- **Admin settings page** – store the API key and choose the Grok model from *Settings → Resume Evaluator*.
- **Shortcode** – embed the form anywhere with `[career_resume_evaluator]`.

---

## Requirements

| Requirement | Minimum version |
|---|---|
| WordPress | 5.9 |
| PHP | 7.4 |
| PHP extension | `zip` (ZipArchive) |
| xAI account | [console.x.ai](https://console.x.ai/) |

---

## Installation

1. Download or clone this repository.
2. Copy (or symlink) the `career-resume-evaluator` folder into your WordPress `wp-content/plugins/` directory.
3. In the WordPress admin, navigate to **Plugins** and activate **Career Resume Evaluator**.
4. Go to **Settings → Resume Evaluator** and enter your **xAI Grok API key**.
5. Add the shortcode `[career_resume_evaluator]` to any page or post.

---

## Configuration

| Option | Default | Description |
|---|---|---|
| **xAI Grok API Key** | *(empty)* | Your secret API key from [console.x.ai](https://console.x.ai/). |
| **Model** | `grok-3-latest` | The xAI model name to use for analysis. |

---

## Usage

Place the shortcode on any page:

```
[career_resume_evaluator]
```

Users will see a form with:

1. A **file picker** (DOCX only).
2. A **career goals** textarea.
3. An **Evaluate My Resume** button.

After submission the plugin will:

1. Validate and temporarily store the uploaded DOCX.
2. Extract plain text from the document.
3. Send the text and career goals to the Grok API.
4. Display a results table with suggested career paths.

---

## File Structure

```
career-resume-evaluator/
├── career-resume-evaluator.php      # Main plugin bootstrap file
├── includes/
│   ├── class-docx-parser.php        # DOCX → plain text extraction
│   ├── class-grok-api.php           # xAI Grok API client
│   └── class-resume-evaluator.php   # Shortcode, AJAX, admin settings
├── assets/
│   ├── css/style.css                # Frontend styles
│   └── js/script.js                 # Frontend AJAX logic
└── templates/
    └── form-template.php            # Shortcode HTML template
```

---

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
