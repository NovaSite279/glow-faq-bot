# WhatsApp Cloud API, and what I'd improve

**Connecting to WhatsApp:**
- **Webhook:** add `public/webhook.php` and register it in the Meta app. A GET request answers Meta's verification challenge with a verify token from `.env`; for each POST, check the `X-Hub-Signature-256` signature, then read the text and phone number from `entry[].changes[].value.messages[]`.
- **Reply:** answer Meta with 200 straight away, then get the reply from the same `Bot::respond()` and send it with `POST graph.facebook.com/v23.0/{phone-number-id}/messages`.
- **Memory:** key each customer's history by phone number in MySQL or SQLite instead of the PHP session, and skip repeated message ids, because Meta retries.
- **Handoff:** pause the bot for that number and alert the team, who reply from the WhatsApp Business inbox.

**With more time** I'd:
- connect the real diary so the bot can offer open slots;
- add a small inbox for handed-off chats;
- use the model's JSON-schema output;
- run the test questions automatically on every change, with more trick questions.
