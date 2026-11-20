Plain-text mail bodies, one file per document type and language: <type>.<lang>.twig.
Rendered by App\Mail\DocumentMailer with its own Twig environment (autoescape OFF: the output is
plain text and is never used as HTML). Every variable is a plain string from the document's
snapshots with control characters removed. To mail another document type (offers, dunning
letters) add <type>.de/pl/en.twig here, the `mail.subject_<type>` keys in lang/*.php and the type
to DocumentMailer::TYPES.
