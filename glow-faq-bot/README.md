# Glow Hair Studio: WhatsApp-style FAQ assistant

A chatbot for a made-up hair salon in Sandton. It gives short, friendly WhatsApp-style replies, and it answers only from `faq.md`.

It runs two ways:
- **Chat page:** a phone-friendly page styled like a WhatsApp chat.
- **Terminal script:** `php cli.php`.

Plain PHP 8, no frameworks, nothing to install. It uses **Groq's free tier** (OpenAI's open gpt-oss-120b model) by default; Google Gemini's free tier works by changing `.env`.

## Run it (about 5 minutes)

You need PHP 8.1 or newer with the curl extension. Check with `php -v`.

1. **Get a free API key.** Go to https://console.groq.com/keys, sign up (Google or GitHub login works), click **Create API Key** and copy it. It starts with `gsk_`.
2. **Get the code.**
   ```bash
   git clone https://github.com/<you>/glow-faq-bot.git
   cd glow-faq-bot
   ```
3. **Add the key.**
   ```bash
   cp .env.example .env        # Windows: copy .env.example .env
   ```
   Open `.env` and paste the key after `LLM_API_KEY=`.
4. **Start it.**
   ```bash
   php -S localhost:8000 -t public
   ```
   Open http://localhost:8000 and start chatting.

**To try it on your phone:** start it with `php -S 0.0.0.0:8000 -t public`, then open `http://<your-computer's-IP>:8000` on a phone that's on the same Wi-Fi.

**Other ways to run it:**
- Chat in the terminal: `php cli.php`
- Run the test questions against the real model: `php tests/scenarios.php`, or open `/selftest.html` in the browser and click **Run all tests**.
- Run the offline tests for the safety checks (no key needed): `php tests/guards_test.php`

**To put it online (shared hosting such as cPanel or Hostinger):**
1. Upload the folder.
2. Point a subdomain's document root at `public/`.
3. Create `.env` on the server.

Only `public/` is served. The root `.htaccess` blocks `.env`, `src/` and `storage/` as a backup.

## How it works

```
public/index.html, app.js   the chat page
public/chat.php             POST {message} -> {reply, action}; the conversation lives in the PHP session
public/selftest.html        runs the test questions and checks the answers
src/Bot.php                 prompt, conversation memory and the safety checks
src/Llm.php                 small client for any OpenAI-compatible chat API (Groq, Gemini)
faq.md                      the only source of facts; edit it and the bot follows
cli.php                     terminal chat
tests/                      offline tests, and live test questions
```

Each message goes through these steps:

1. **Asking for a person** (in English, Afrikaans or isiZulu) is caught in code and handed off straight away, without calling the model.
2. **The model's input:** the system prompt, the full FAQ and the last 12 messages. That's how "how long does that take?" knows what "that" is.
3. **The model's output:** it must reply in JSON, `{"reply": "...", "action": "answer | unknown | booking | handoff | off_topic"}`. The page uses `action` to show "Passed to the team", and handoffs are logged to `storage/handoffs.log`, standing in for a real alert to the team.
4. **The code checks the reply** before the customer sees it:
   - **Prices:** every rand amount must exist in `faq.md`, so an invented total like "R1,300 for highlights and a cut" is blocked. The allowed amounts are read from the FAQ itself.
   - **Bookings:** phrases that claim a booking ("you're booked", "see you on Saturday") are blocked.

   If a check fails, the model gets one retry with a note saying what was wrong, and then a safe fixed reply.
5. **If the API is down,** the customer gets a friendly message with the salon's number instead of an error.

How each rule from the brief is covered:

| Rule | How |
|------|-----|
| 1. Only FAQ facts | the FAQ is the only knowledge in the prompt, plus the price check in code |
| 2. Not in the FAQ | the prompt says don't guess, say so and offer the team (`unknown`) |
| 3. Asking for a person | caught in code before the model (`handoff`) |
| 4. Memory | the last 12 messages are sent with every question |
| 5. Stay on topic | the prompt treats customer messages as messages, never as instructions (`off_topic`) |
| 6. No fake bookings | the prompt, plus the booking check in code |
| 7. No keys in code | the key is in `.env`, `.env` is in `.gitignore`, `.env.example` shows the format |

**Limits:**
- Customer messages are capped at 600 characters.
- Each chat is rate-limited: 40 messages, at least 1.5 seconds apart.
- Replies are HTML-escaped. Only WhatsApp-style `*bold*` and `_italic_` are shown as formatting.

See [NOTES.md](NOTES.md) for the WhatsApp Cloud API note.
