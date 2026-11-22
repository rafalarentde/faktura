Plain-text mail bodies, one file per document type and language: <type>.<lang>.twig.
Rendered by App\Mail\DocumentMailer with its own Twig environment (autoescape OFF: the output is
plain text and is never used as HTML). Every variable is a plain string from the document's
snapshots with control characters removed. To mail another document type (offers, dunning
letters) add <type>.de/pl/en.twig here (dunning: done in Task 21; extra variables level, invoice, invoice_date, pay_by), the `mail.subject_<type>` keys in lang/*.php and the type
to DocumentMailer::TYPES.

Tone policy: customer-facing mail text (these bodies and the subjects `mail.subject_*`) is formal
("Sie" / "Państwo" / neutral English). The app UI itself uses the informal "du". The same formal
tone applies to the offer and dunning mails added in Tasks 19 and 21.
