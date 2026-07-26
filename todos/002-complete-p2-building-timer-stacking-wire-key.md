---
status: pending
priority: p2
issue_id: 002
tags: [code-review, frontend, livewire]
dependencies: []
---
# Building-backoff-timer kan stable ved Livewire-morph

## Problem Statement
Building-noten (company-structure.blade.php) bruger x-init="setTimeout(...)" uden wire:key. Hvis Livewire fjerner+genindsætter elementet (DOM-diff-forskydning) fyrer x-init igen → overlappende timere → MAX_PROPERTIES_ATTEMPTS (8) brændes op til dobbelt så hurtigt.

## Findings
- julik-frontend-races-reviewer (P2): ingen cancel-token; re-init uforudsigelig

## Proposed Solutions
1. (Anbefalet) wire:key="properties-status-{{ $propertiesAttempts }}" på noten → præcis én ny timer pr. attempt, gammel node fjernes garanteret. Effort: Small. Risk: minimal.

## Acceptance Criteria
- [ ] wire:key på building-noten; suite grøn
