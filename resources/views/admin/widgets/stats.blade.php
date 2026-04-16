<div class="row">
    @foreach($widgetData as $stat)
        <div class="col-sm-6 col-lg-4 col-xl-2">
            <a href="{{ route($stat['route']) }}" class="text-decoration-none">
                <div class="card text-white bg-{{ $stat['color'] }} mb-3">
                    <div class="card-body d-flex align-items-center justify-content-between py-3">
                        <div>
                            <div class="h3 mb-0 font-weight-bold">{{ number_format($stat['count']) }}</div>
                            <div class="text-white-50 small text-uppercase font-weight-bold">{{ $stat['label'] }}</div>
                        </div>
                        <i class="{{ $stat['icon'] }} fa-2x opacity-50"></i>
                    </div>
                </div>
            </a>
        </div>
    @endforeach
</div>
