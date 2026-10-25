<?php
// =================================================================
// training_notice_popup.php
// Dismissible "Training is Mandatory" popup — bigger, no scrollbar
// Include with:  <?php include __DIR__ . '/training_notice_popup.php'; 
?>

<div id="trainingNoticePopup" class="fixed inset-0 z-[9999] hidden items-center justify-center p-3 bg-black/60 backdrop-blur-sm">
    <div class="tnp-shell relative rounded-2xl shadow-2xl overflow-hidden"
        style="background: linear-gradient(160deg,#06301C,#14773F);">

        <!-- Close button (top-right) -->
        <button type="button"
            id="tnpClose"
            aria-label="Close notice"
            class="absolute top-3 right-3 z-20 w-10 h-10 rounded-full bg-white/15 hover:bg-white/30 text-white text-2xl leading-none font-bold flex items-center justify-center transition">
            &times;
        </button>

        <!-- Inner area (scrolls only if content truly cannot fit) -->
        <div class="tnp-body relative overflow-y-auto overflow-x-hidden">

            <!-- Decorative floating circles -->
            <div class="pointer-events-none absolute -top-24 -right-24 w-96 h-96 rounded-full bg-yellow-400/20 animate-pulse"></div>
            <div class="pointer-events-none absolute -bottom-24 -left-24 w-72 h-72 rounded-full bg-white/5"></div>

            <div class="relative z-[1] p-6 md:p-8 text-white">

                <!-- Badge -->
                <span class="inline-flex items-center gap-2 bg-[#FFB800] text-[#06301C] font-extrabold tracking-wider px-4 py-1.5 rounded-full text-xs shadow-md">
                    <span class="w-5 h-5 rounded-full bg-[#06301C] text-[#FFB800] inline-flex items-center justify-center text-xs font-black">!</span>
                    IMPORTANT NOTICE
                </span>

                <!-- Title -->
                <h1 class="text-3xl md:text-4xl font-extrabold mt-3 mb-1.5 leading-tight">
                    <span style="background:linear-gradient(90deg,#FFB800,#9be15d,#FFB800);background-size:200%;-webkit-background-clip:text;background-clip:text;color:transparent;animation:tnpShine 3s linear infinite;">
                        Training is Mandatory.
                    </span>
                </h1>
                <p class="text-[#C9EBD6] text-sm md:text-base">
                    Keep your training up to date — every employee, every time.
                </p>

                <!-- Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-5">

                    <!-- All Employees -->
                    <div class="bg-white text-[#06301C] rounded-xl overflow-hidden shadow-md">
                        <h2 class="flex items-center gap-2 px-4 py-2.5 text-white text-sm md:text-base font-bold"
                            style="background: linear-gradient(90deg,#2EAD5F,#5CCB88);">
                            👤 ALL EMPLOYEES
                        </h2>
                        <ul class="p-2.5">
                            <li class="tnp-check flex items-start gap-2.5 p-2 rounded-lg cursor-pointer hover:bg-[#EEF8F2] transition select-none"
                                data-group="emp">
                                <span class="tnp-ck flex-none w-6 h-6 rounded-full border-[3px] border-[#2EAD5F] grid place-items-center text-transparent font-black text-xs transition">✓</span>
                                <div class="min-w-0">
                                    <b class="block text-sm md:text-[15px]">Own your training</b>
                                    <small class="text-[#4A6657] text-xs md:text-sm">Follow your personal training plan &amp; schedule.</small>
                                </div>
                            </li>
                            <li class="tnp-check flex items-start gap-2.5 p-2 rounded-lg cursor-pointer hover:bg-[#EEF8F2] transition select-none"
                                data-group="emp">
                                <span class="tnp-ck flex-none w-6 h-6 rounded-full border-[3px] border-[#2EAD5F] grid place-items-center text-transparent font-black text-xs transition">✓</span>
                                <div class="min-w-0">
                                    <b class="block text-sm md:text-[15px]">Attend &amp; pass</b>
                                    <small class="text-[#4A6657] text-xs md:text-sm">Join every scheduled course and pass all exams.</small>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <!-- Team Leaders -->
                    <div class="bg-white text-[#06301C] rounded-xl overflow-hidden shadow-md">
                        <h2 class="flex items-center gap-2 px-4 py-2.5 text-white text-sm md:text-base font-bold"
                            style="background: linear-gradient(90deg,#0B6E4F,#1FA37A);">
                            ⭐ TEAM LEADERS
                        </h2>
                        <ul class="p-2.5">
                            <li class="tnp-check flex items-start gap-2.5 p-2 rounded-lg cursor-pointer hover:bg-[#EEF8F2] transition select-none"
                                data-group="tl">
                                <span class="tnp-ck flex-none w-6 h-6 rounded-full border-[3px] border-[#0B6E4F] grid place-items-center text-transparent font-black text-xs transition">✓</span>
                                <div class="min-w-0">
                                    <b class="block text-sm md:text-[15px]">Follow up</b>
                                    <small class="text-[#4A6657] text-xs md:text-sm">Check each team member's training status.</small>
                                </div>
                            </li>
                            <li class="tnp-check flex items-start gap-2.5 p-2 rounded-lg cursor-pointer hover:bg-[#EEF8F2] transition select-none"
                                data-group="tl">
                                <span class="tnp-ck flex-none w-6 h-6 rounded-full border-[3px] border-[#0B6E4F] grid place-items-center text-transparent font-black text-xs transition">✓</span>
                                <div class="min-w-0">
                                    <b class="block text-sm md:text-[15px]">Enforce</b>
                                    <small class="text-[#4A6657] text-xs md:text-sm">Make sure no one misses required training.</small>
                                </div>
                            </li>
                            <li class="tnp-check flex items-start gap-2.5 p-2 rounded-lg cursor-pointer hover:bg-[#EEF8F2] transition select-none"
                                data-group="tl">
                                <span class="tnp-ck flex-none w-6 h-6 rounded-full border-[3px] border-[#0B6E4F] grid place-items-center text-transparent font-black text-xs transition">✓</span>
                                <div class="min-w-0">
                                    <b class="block text-sm md:text-[15px]">Keep it current</b>
                                    <small class="text-[#4A6657] text-xs md:text-sm">No overdue or expired training in your team.</small>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Reminder chips -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-5">
                    <div class="bg-white/10 border border-white/20 rounded-xl px-4 py-3">
                        <strong class="block text-xl text-[#FFB800]">2 yrs</strong>
                        <span class="text-xs text-[#E1F5E9] leading-tight block mt-0.5">Refresher training required every 2 years</span>
                    </div>
                    <div class="bg-white/10 border border-white/20 rounded-xl px-4 py-3">
                        <strong class="block text-xl text-[#FFB800]">Latest</strong>
                        <span class="text-xs text-[#E1F5E9] leading-tight block mt-0.5">Use the latest ETTPM revision from the Portal</span>
                    </div>
                    <div class="bg-white/10 border border-white/20 rounded-xl px-4 py-3">
                        <strong class="block text-xl text-[#FFB800]">Safety</strong>
                        <span class="text-xs text-[#E1F5E9] leading-tight block mt-0.5">Qualified people keep aircraft safe</span>
                    </div>
                </div>

                <!-- Progress bar -->
                <div class="mt-5 h-2 bg-white/15 rounded-full overflow-hidden">
                    <div id="tnpProgress" class="h-full w-0 bg-gradient-to-r from-[#FFB800] to-[#9be15d] transition-all duration-500"></div>
                </div>

                <!-- Footer actions -->
                <div class="flex flex-wrap gap-3 justify-between items-center mt-4">
                    <div>
                        <small class="text-[#C9EBD6] block text-sm">
                            Ethiopian MRO | ETTPM &nbsp;•&nbsp;
                            <b class="text-[#FFB800]">Train • Qualify • Fly Safe</b>
                        </small>
                        <small class="text-xs text-[#C9EBD6] block mt-1" id="tnpHint">
                            Tap each point to tick it off, then acknowledge.
                        </small>
                    </div>
                    <button type="button"
                        id="tnpAck"
                        class="bg-[#FFB800] hover:bg-[#ffca3a] text-[#06301C] font-extrabold text-sm px-7 py-3 rounded-full shadow-lg transition transform hover:scale-105 disabled:opacity-60 disabled:cursor-not-allowed"
                        disabled>
                        ✔ I understand
                    </button>
                </div>

            </div>
        </div>
    </div>
</div>

<style>
    /* Popup shell: bigger width, still fits any screen */
    .tnp-shell {
        width: min(920px, 94vw);
        max-width: 94vw;
    }

    .tnp-shell * {
        min-width: 0;
        max-width: 100%;
    }

    /* Inner area: cap only, does not force a height.
       max-height: 88vh → popup grows with content until it fills 88% of screen,
       then it scrolls internally (only on tiny screens). */
    .tnp-body {
        max-height: 88vh;
    }

    /* Slim scrollbar (appears only if the viewport is very short) */
    .tnp-body::-webkit-scrollbar {
        width: 5px;
    }

    .tnp-body::-webkit-scrollbar-track {
        background: transparent;
    }

    .tnp-body::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, .3);
        border-radius: 3px;
    }

    @keyframes tnpShine {
        to {
            background-position: 200%;
        }
    }
</style>

<script>
    (function() {
        const POPUP_ID = 'trainingNoticePopup';
        const STORAGE_KEY = 'training_notice_dismissed_v1';

        const popup = document.getElementById(POPUP_ID);
        if (!popup) return;

        // ═══════════════════════════════════════════════════════════
        // Scope ALL lookups to the popup itself so this script never
        // collides with the inline notice card in index.php that
        // reuses the same .tnp-check / #tnpProgress / #tnpHint ids.
        // ═══════════════════════════════════════════════════════════
        const closeBtn = popup.querySelector('#tnpClose');
        const ackBtn = popup.querySelector('#tnpAck');
        const hintEl = popup.querySelector('#tnpHint');
        const progress = popup.querySelector('#tnpProgress'); // ✅ fixed: bare id, scoped to popup
        const items = popup.querySelectorAll('.tnp-check');

        let dismissed = false;
        try {
            dismissed = localStorage.getItem(STORAGE_KEY) === '1';
        } catch (e) {}
        if (dismissed) return;

        setTimeout(() => {
            popup.classList.remove('hidden');
            popup.classList.add('flex');
        }, 300);

        function closePopup() {
            try {
                localStorage.setItem(STORAGE_KEY, '1');
            } catch (e) {}
            popup.classList.add('hidden');
            popup.classList.remove('flex');
        }

        closeBtn?.addEventListener('click', closePopup);
        popup.addEventListener('click', function(e) {
            if (e.target === popup) closePopup();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !popup.classList.contains('hidden')) closePopup();
        });

        function updateProgress() {
            const done = Array.from(items).filter(li => li.classList.contains('done')).length;
            const total = items.length;
            const pct = total ? (done / total) * 100 : 0;
            if (progress) progress.style.width = pct + '%';

            items.forEach(li => {
                const ck = li.querySelector('.tnp-ck');
                if (!ck) return;
                const group = li.dataset.group || 'emp';
                const color = group === 'tl' ? '#0B6E4F' : '#2EAD5F';
                if (li.classList.contains('done')) {
                    ck.style.background = color;
                    ck.style.color = '#fff';
                    ck.style.transform = 'scale(1.15) rotate(360deg)';
                } else {
                    ck.style.background = '';
                    ck.style.color = 'transparent';
                    ck.style.transform = '';
                }
            });

            if (!ackBtn) return;
            if (done === total && total > 0) {
                ackBtn.disabled = false;
                ackBtn.textContent = '✔ I understand';
                if (hintEl) hintEl.textContent = 'All points checked — tap to confirm.';
            } else {
                ackBtn.disabled = true;
                if (hintEl) hintEl.textContent = done + ' of ' + total + ' checked. Tap each point to tick it off.';
            }
        }

        items.forEach(li => {
            li.addEventListener('click', function() {
                li.classList.toggle('done');
                updateProgress();
            });
        });

        ackBtn?.addEventListener('click', function() {
            ackBtn.textContent = '🎉 Thank you!';
            ackBtn.disabled = true;
            ackBtn.style.background = '#22c55e';
            ackBtn.style.color = '#fff';
            if (hintEl) hintEl.textContent = 'Acknowledged. Keep your training up to date!';
            setTimeout(closePopup, 1200);
        });

        updateProgress();
    })();
</script>