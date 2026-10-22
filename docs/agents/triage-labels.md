# Triage Labels

The skills speak in terms of five canonical triage roles. This file maps those roles to the actual label strings used in this repo's issue tracker.

| Label in mattpocock/skills | Label in our tracker | Meaning                                  |
| -------------------------- | -------------------- | ---------------------------------------- |
| `needs-triage`             | `needs-triage`       | Maintainer needs to evaluate this issue  |
| `needs-info`               | `needs-info`         | Waiting on reporter for more information |
| `ready-for-agent`          | `ready-for-agent`    | Fully specified, ready for an AFK agent  |
| `ready-for-human`          | `ready-for-human`    | Requires human implementation            |
| `wontfix`                  | `wontfix`            | Will not be actioned                     |

When a skill mentions a role (e.g. "apply the AFK-ready triage label"), use the corresponding label string from this table.

Edit the "Label in our tracker" column to match whatever vocabulary you actually use.

## Status of label creation

All five labels exist on the GitHub repository (`ryry55/project-rpl`). `wontfix` was already a
GitHub default, so only four needed creating. If the repo is ever recreated:

```powershell
gh label create needs-triage    --description "Maintainer needs to evaluate this issue"
gh label create needs-info      --description "Waiting on reporter for more information"
gh label create ready-for-agent --description "Fully specified, ready for an AFK agent"
gh label create ready-for-human --description "Requires human implementation"
gh label create wontfix         --description "Will not be actioned"
```

`/triage` will apply these strings. Creating them up front avoids duplicate labels that mean the
same thing under a different name.

## Ticket order in this project

Tickets here are worked blockers-first, which matters for the course demo: the grader can see the
dependency graph on the issue list. The build order implied by the business rules is roughly

1. schema + auth + room/tenant management (the floor every later ticket stands on),
2. rate and penalty-parameter management (R-06 lives here),
3. invoice generation (R-01, R-02),
4. payment, penalty, aging (R-03, R-04, R-05),
5. reports and tenant-scoped visibility (R-07).

This is a shape, not a commitment. `/to-tickets` produces the real edges.
