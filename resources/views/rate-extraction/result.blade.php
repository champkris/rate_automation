<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate Card Extraction - Results</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <div class="max-w-2xl mx-auto">
            <!-- Header -->
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-gray-800">Extraction Complete</h1>
                <p class="text-gray-600 mt-2">Your rate card has been successfully processed</p>
            </div>

            <!-- Success Card -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <div class="flex items-center justify-center mb-6">
                    <div class="bg-green-100 rounded-full p-4">
                        <svg class="h-16 w-16 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>

                <!-- Summary Stats -->
                <div class="grid grid-cols-2 gap-4 mb-6">
                    <div class="bg-blue-50 rounded-lg p-4 text-center">
                        <p class="text-3xl font-bold text-blue-600">{{ $count }}</p>
                        <p class="text-sm text-gray-600">Total Rates Extracted</p>
                    </div>
                    <div class="bg-purple-50 rounded-lg p-4 text-center">
                        <p class="text-3xl font-bold text-purple-600">{{ count($carrierSummary) }}</p>
                        <p class="text-sm text-gray-600">Carriers Found</p>
                    </div>
                </div>

                <!-- Carrier Breakdown -->
                @if(count($carrierSummary) > 0)
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-3">Carrier Breakdown</h3>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <div class="space-y-2">
                            @foreach($carrierSummary as $carrier => $rateCount)
                            <div class="flex justify-between items-center">
                                <span class="font-medium text-gray-700">{{ $carrier }}</span>
                                <span class="bg-blue-100 text-blue-800 text-sm font-medium px-3 py-1 rounded-full">{{ $rateCount }} rates</span>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                @endif

                <!-- Download Button -->
                <div class="flex flex-col space-y-3">
                    <a href="{{ route('rate-extraction.download', $filename) }}"
                       class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-4 rounded-lg text-center transition-colors flex items-center justify-center">
                        <svg class="h-5 w-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                        </svg>
                        Download Excel File
                    </a>
                    <a href="{{ route('rate-extraction.index') }}"
                       class="w-full bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-3 px-4 rounded-lg text-center transition-colors">
                        Extract Another File
                    </a>
                </div>
            </div>

            <!-- Output Format Info -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-3">Output Format (FCL_EXP)</h3>
                <p class="text-sm text-gray-600 mb-4">The extracted file contains the following columns:</p>
                <div class="grid grid-cols-3 md:grid-cols-4 gap-2 text-xs">
                    <span class="bg-gray-100 px-2 py-1 rounded">CARRIER</span>
                    <span class="bg-gray-100 px-2 py-1 rounded">POL</span>
                    <span class="bg-gray-100 px-2 py-1 rounded">POD</span>
                    <span class="bg-gray-100 px-2 py-1 rounded">CUR</span>
                    <span class="bg-gray-100 px-2 py-1 rounded">20'</span>
                    <span class="bg-gray-100 px-2 py-1 rounded">40'</span>
                    <span class="bg-gray-100 px-2 py-1 rounded">40 HQ</span>
                    <span class="bg-gray-100 px-2 py-1 rounded">ETD BKK</span>
                    <span class="bg-gray-100 px-2 py-1 rounded">ETD LCH</span>
                    <span class="bg-gray-100 px-2 py-1 rounded">T/T</span>
                    <span class="bg-gray-100 px-2 py-1 rounded">T/S</span>
                    <span class="bg-gray-100 px-2 py-1 rounded">FREE TIME</span>
                    <span class="bg-gray-100 px-2 py-1 rounded">VALIDITY</span>
                    <span class="bg-gray-100 px-2 py-1 rounded">REMARK</span>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
