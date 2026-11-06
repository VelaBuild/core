@extends('vela::layouts.admin')

@section('content')
<div class="card">
    <div class="card-header">
        <h4 class="mb-0"><i class="fas fa-cloud-upload-alt mr-2"></i> Move to Vela Hosted</h4>
    </div>
    <div class="card-body">
        <p>
            Put this site on <a href="https://vela.build/pricing" target="_blank" rel="noopener">Vela Hosted</a>: pages, posts, uploads,
            themes, settings and users all come with it, so you sign in there with the same login as here.
        </p>

        @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        @if($blockers)
            @foreach($blockers as $blocker)
                <div class="alert alert-warning">{{ $blocker }}</div>
            @endforeach
        @else
            <h5 class="mt-4">Option 1: upload a file</h5>
            <ol>
                <li>Download this site as one file.</li>
                <li>In the Vela Hosted panel, open your site and choose <strong>Import</strong>, then upload the file.</li>
            </ol>
            <form method="POST" action="{{ route('vela.admin.move-to-hosted.download') }}">
                @csrf
                <button class="btn btn-primary"><i class="fas fa-download mr-1"></i> Download site archive</button>
                <small class="text-muted ml-2">This can take a minute for a large site.</small>
            </form>

            <h5 class="mt-4">Option 2: one command</h5>
            <p class="mb-1">Copy the import token from your hosted site's <strong>Import</strong> page, then run this in the site's folder:</p>
            <pre class="bg-light p-2"><code>php artisan vela:push --token=YOUR_TOKEN</code></pre>
            <p class="text-muted mb-0">Run <code>php artisan vela:push</code> again any time to replace the hosted site with this one.</p>
        @endif

        <p class="text-muted mt-4 mb-0">
            Importing replaces everything on the hosted site you pick. A backup is taken first, and the import is undone automatically if the site doesn't start.
        </p>
    </div>
</div>
@endsection
