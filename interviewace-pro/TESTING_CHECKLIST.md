# InterviewAce Manual Testing Checklist
# Run this after every installation before reporting issues

## Installation Verification
- [ ] Open DevTools → Sources → find app.js → Line 1 says: `/* InterviewAce v2.6.4 */`
- [ ] If it says any other version, the old file is still on the server

## CSS Smoke Test (open DevTools → Console, paste each line)
```js
// Check textarea background
getComputedStyle(document.querySelector('textarea')).backgroundColor
// Expected: "rgb(26, 26, 46)" (which is #1A1A2E = var(--s2))
// If "rgb(255, 255, 255)" → CSS not loaded or old file cached

// Check sidebar width
getComputedStyle(document.querySelector('.ia-sidebar')).width
// Expected: "236px" on desktop

// Check step dot is circular
getComputedStyle(document.querySelector('.ia-step__dot')).borderRadius
// Expected: "50%"
```

## Functional Tests (do these in order)

### Setup Wizard
1. Click "New Interview" from Dashboard
2. Step 1: Step bar should show 4 circles (not bars) with numbers 1-4 inside
   - Circle 1 should be purple/filled
   - Circles 2-4 should be dim/empty
3. Select a round type (e.g. General) — card should highlight purple
4. Select a company chip — should highlight purple
5. Click Continue → Step 2
   - Circle 1 should turn green with checkmark
   - Circle 2 should turn purple
6. In the JD textarea: background should be dark (#1A1A2E), NOT white
7. Type some text → it should show in dark text on dark background
8. Click Skip → Step 3

### Interview Room
9. After setup completes → Interview Room opens
10. Top bar: HR badge, timer at 00:00, Q1/~18 counter, End button
11. Priya photo should show (the configured avatar, not graduation photo)
    - If graduation photo: go to WP Admin → InterviewAce → API Keys → Priya Avatar → change it
12. Greeting text should appear in the question overlay area
13. Purple button at bottom should say "🔊 Hear Priya & Start"
14. Click it → browser asks for microphone permission (first time only)
15. Priya should SPEAK the greeting aloud (Web Speech if no ElevenLabs key)
16. After speaking, button should change to mic recording state
17. Speak something → transcript appears in "YOUR ANSWER" box
18. Click Submit → Priya enters PROCESSING (dots animation)
19. Priya receives next question → SPEAKING state (waveform animation)
20. After Priya finishes speaking → mic button reappears
    - This is the critical test: if step 20 doesn't happen, the speak() bug is still present

### Mobile (resize browser to 375px width or use DevTools mobile emulation)
21. Sidebar should disappear → hamburger menu appears at top
22. Interview room: Priya photo at top (~240px tall)
23. Transcript + answer box in scrollable middle section
24. Mic/submit button ALWAYS visible at bottom (does not scroll away)
25. Long answers should WRAP and not overflow horizontally

## Known Non-Code Issues
- Graduation photo = admin setting, not a code bug
  Fix: WP Admin → InterviewAce → API Keys → Priya Avatar → click image → choose correct photo

