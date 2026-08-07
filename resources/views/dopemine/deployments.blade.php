<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;">
    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.2rem;letter-spacing:-0.01em;">Our Deployments</h1>
        <p style="font-size:0.78rem;color:#52525b;margin:0;">Which certified mechanics {{ auth()->user()->currentTeam->name ?? 'your team' }} currently uses.</p>
    </div>

    <livewire:mechanic-deployments />
</div>
</x-app-layout>
