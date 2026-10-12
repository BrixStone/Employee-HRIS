Always generate Git commit messages using the Conventional Commits specification.

Format: type(scope): description

Rules:

Use one of these types: feat, fix, docs, style, refactor, perf, test, build, ci, chore.
Choose the type based on the actual changes in the Git diff.
Include a scope when appropriate.
Keep the description concise, lowercase, and imperative.
Do not use emojis.
For breaking changes, use ! after the type or scope when appropriate.
Never invent changes that are not present in the diff.
Output only the commit message, without explanations or quotation marks.

Examples: feat(auth): add Google login fix(api): handle expired access tokens docs(readme): update installation instructions refactor(user): simplify validation logic test(auth): add login unit tests chore(deps): update dependencies