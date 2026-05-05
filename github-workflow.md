# Workflow: GitHub for Issue Tracking and Time Tracking

## Required tools

- GitHub (internal + client access)  
- Time Doctor (internal)

---

## Key constraint

Time Doctor only reports:
- **Project** → GitHub repo  
- **Task** → Issue title  

To support billing (support, warranty, sprint work), we use:
- Separate repos for **support / warranty / code**
- Standardized issue titles (via GitHub Actions)

---

## Support workflow

1. User submits support request:  
   https://github.com/openplus/support-rsams/issues  
2. Issue is created and sent to Slack  
3. Craig or Sang triages, assigns, and categorizes  

---

## Warranty workflow

1. Support request is submitted  
2. If it qualifies as warranty → issue is moved to the warranty repo  

---

## Sprint / project work

1. In the code repo, create milestones (e.g. `2026 sprint`, `support`, `warranty`)  
2. Assign a milestone when creating the issue  
3. A GitHub Action prepends the milestone to the issue title  

Example:
```
[2026 sprint] Update notification system
```

---

## Time tracking

No change to developer workflow.

- Devs start timers from GitHub issues  
- Time Doctor captures:
  - Project → repo name  
  - Task → issue title  

---

## Reporting in Time Doctor

Mapping:

| Concept        | Time Doctor shows                  |
|----------------|----------------------------------|
| Project        | github.com: site-rsams           |
| Support        | github.com: support-rsams        |
| Warranty       | github.com: warranty-rsams       |
| Task           | Issue title                      |

Example:

```
Project: github.com: site-rsams
Task: [2026 sprint] Update notification system
```

---

## GitHub project board

A single GitHub Project can track issues across multiple repos:
- support  
- warranty  
- code  

Example:  
https://github.com/orgs/openplus/projects/17/views/1  

Views include:
- Kanban  
- Timeline  
- Priority  
- My items  
- Roadmap  

This replaces multiple monday.com boards with a single unified view.

---

## Result

### Improvements over monday.com

- One project board across all work (support, warranty, development)  
- No duplication across boards  
- Better visibility of related work  

---

## Tradeoff (important)

To support Time Doctor reporting:
- Different **work types require different repos**

For sprint work:
- All work stays in the code repo  
- Sprint is identified via milestone prefix  

Example:

```
Project: github.com: site-rsams
Task: [2026 sprint] Update notification system
```

If needed, reporting can be filtered by:
- date range  
- issue title prefix ([2026 sprint])  

---

## Optional enhancement

We can extend the prefix to include labels:

```
[2026 sprint] (Sprint 2 - Jan 1–15, Feature) Update notification system
```

This gives more detail in Time Doctor without changing workflow.

---

## Summary

- GitHub becomes the single system for all work  
- Time Doctor reporting remains intact  
- Billing categories are preserved via repo structure and issue naming  
- One project board provides full visibility across all work  
