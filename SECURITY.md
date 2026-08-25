# Security Policy

## Supported versions

This project is pre-1.0. Security fixes are applied to the latest `0.x` release
only.

| Version | Supported |
|---|---|
| 0.1.x | yes |

## Reporting a vulnerability

Please do not report security issues in a public GitHub issue.

Use GitHub's [private vulnerability
reporting](https://github.com/ikramulmustafa/laravel-rag/security/advisories/new),
or email **rao.ikram42@gmail.com** with `laravel-rag` in the subject.

Please include the version, an outline of the impact, and steps to reproduce.

I will acknowledge your report within 7 days and aim to have a fix or a clear
assessment within 30. As a solo maintainer I cannot promise faster, and I would
rather state that than an SLA I cannot keep. Please give me a chance to release a
fix before disclosing publicly.

## Things worth knowing when you deploy this

Not vulnerabilities in the package, but the places a RAG pipeline tends to leak.

**Retrieval does not enforce authorisation.** `retrieve()` searches every chunk in
the corpus, filtered only by what you pass in `filters`. If different users may
see different documents, you must scope the query yourself — by `collection` or by
a metadata key — and enforce that on the server, not in the prompt. A chunk that
reaches the prompt has effectively been disclosed, whatever the model is
instructed to do with it.

**Ingested content reaches the model.** Anything embedded becomes prompt context
later. Treat an ingestion path that accepts user-supplied documents as an indirect
prompt-injection surface, and do not ingest secrets you would not want quoted back.

**Embedding calls leave your infrastructure.** With the OpenAI driver, chunk text
is sent to the configured `base_url`. Whether that is acceptable is a decision
about your data, not about this package.

**The API key comes from config.** Keep `OPENAI_API_KEY` in the environment. Note
that `rag:eval --json` and a saved baseline contain retrieved chunk text and
queries, so treat those files as you would the corpus itself.
