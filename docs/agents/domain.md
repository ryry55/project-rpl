# Domain Docs

How the engineering skills should consume this repo's domain documentation when exploring the codebase.

## Before exploring, read these

- **`GLOSSARY.md`** at the repo root, or
- **`GLOSSARY-MAP.md`** at the repo root if it exists: it points at one `GLOSSARY.md` per context. Read each one relevant to the topic.
- **`docs/adr/`**: read ADRs that touch the area you're about to work in. In multi-context repos, also check `src/<context>/docs/adr/` for context-scoped decisions.

If any of these files don't exist, **proceed silently**. Don't flag their absence; don't suggest creating them upfront. The `/domain-modeling` skill (reached via `/grill-with-docs` and `/improve-codebase-architecture`) creates them lazily when terms or decisions actually get resolved.

## File structure

Single-context repo (most repos):

```
/
├── GLOSSARY.md
├── docs/adr/
│   ├── 0001-event-sourced-orders.md
│   └── 0002-postgres-for-write-model.md
└── src/
```

Multi-context repo (presence of `GLOSSARY-MAP.md` at the root):

```
/
├── GLOSSARY-MAP.md
├── docs/adr/                          ← system-wide decisions
└── src/
    ├── ordering/
    │   ├── GLOSSARY.md
    │   └── docs/adr/                  ← context-specific decisions
    └── billing/
        ├── GLOSSARY.md
        └── docs/adr/
```

## Use the glossary's vocabulary

When your output names a domain concept (in an issue title, a refactor proposal, a hypothesis, a test name), use the term as defined in `GLOSSARY.md`. Don't drift to synonyms the glossary explicitly avoids.

If the concept you need isn't in the glossary yet, that's a signal: either you're inventing language the project doesn't use (reconsider) or there's a real gap (note it for `/domain-modeling`).

## Flag ADR conflicts

If your output contradicts an existing ADR, surface it explicitly rather than silently overriding:

> _Contradicts ADR-0007 (event-sourced orders), but worth reopening because…_

## Repo-specific notes

**Language.** The domain vocabulary in this project is Indonesian, because the report, the DFD, and
the presentation all are. Keep Indonesian for domain nouns that appear in the deliverable
(`tagihan`, `penghuni`, `kontrak`, `denda`, `tunggakan`, `prorata`) and English for code-level
mechanics (`Invoice`, `Tenant`, `Payment` as class names, `repository`, `seam`, `ticket`). A
`GLOSSARY.md` here should record both columns and say which is used where.

**Two source-of-truth documents already exist** and outrank any glossary created later:

- `docs/dfd/README.md` — the data dictionary, the flow tables, and the business rules R-01..R-07.
  This is the project's real domain documentation today.
- `docs/dfd/03-dfd-level-2.md` — process decomposition 3.1..3.5 and 4.1..4.4.

When a skill needs to know what a process or a rule means, read those two before inventing an
interpretation. The glossary's job is to compress them, not to replace them.

**Decisions already made that deserve ADRs but have none yet.** These were settled in
conversation and exist only as prose. If a later task depends on one of them, it is worth
recording as an ADR rather than re-deriving it:

- Scope excludes utility sub-metering, because the target segment is all-in rent.
- Only two external entities; "Petugas" was merged into the owner.
- Penalty is computed on principal (rent + fixed additional fees) with no compounding.
- Penalty parameters live in a data store (D3), not in code, so the owner can change them.
