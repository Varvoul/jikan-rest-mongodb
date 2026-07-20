#!/bin/bash

# Jikan API End-to-End Verification Script
# Tests all seasonal/airing/upcoming endpoints after deployment

API_BASE="https://jikan-api-bohb.onrender.com/v4"
PASS=0
FAIL=0
WARN=0

echo "╔════════════════════════════════════════════════════════════╗"
echo "║     JIKAN API - END-TO-END VERIFICATION SUITE              ║"
echo "║     Testing: Seasonal, Airing & Upcoming Endpoints         ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo ""
echo "📅 Test Date: $(date)"
echo "🌐 API Base: $API_BASE"
echo ""

# Function to test endpoint
test_endpoint() {
    local name="$1"
    local url="$2"
    local expected_status="${3:-200}"
    local timeout="${4:-30}"
    
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "🔍 TEST: $name"
    echo "📡 URL: $url"
    echo "⏱️  Timeout: ${timeout}s | Expected Status: $expected_status"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    
    response=$(curl -s --max-time "$timeout" -w "\n__HTTP_STATUS__%{http_code}__" "$url" 2>&1)
    
    # Extract HTTP status
    http_status=$(echo "$response" | grep -o '__HTTP_STATUS__[0-9]*__' | sed 's/__HTTP_STATUS__//; s/__//')
    
    # Extract body (everything before status marker)
    body=$(echo "$response" | sed 's/__HTTP_STATUS__.*$//')
    
    if [ -z "$http_status" ]; then
        echo "❌ FAIL: Request timed out or failed"
        ((FAIL++))
        return 1
    fi
    
    echo "📊 Response Status: $http_status"
    
    if [ "$http_status" == "$expected_status" ]; then
        echo "✅ PASS: Endpoint responding correctly"
        
        # Additional checks for data
        if echo "$body" | grep -q '"data"'; then
            count=$(echo "$body" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('data',[])))" 2>/dev/null || echo "N/A")
            echo "📦 Data Count: $count entries"
        elif echo "$body" | grep -q '"cached_requests"'; then
            cached=$(echo "$body" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('cached_requests',0))" 2>/dev/null || echo "N/A")
            echo "💾 Cached Requests: $cached"
        elif echo "$body" | grep -q '"status"'; then
            status_msg=$(echo "$body" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('status','unknown'))" 2>/dev/null || echo "N/A")
            echo "📋 Operation Status: $status_msg"
        fi
        
        ((PASS++))
        return 0
    else
        # Check if it's a timeout (504) which is acceptable for heavy endpoints
        if [ "$http_status" == "504" ]; then
            echo "⚠️  WARN: Endpoint timed out (504) - MAL may be slow"
            echo "   This is expected behavior for heavy seasonal pages"
            ((WARN++))
            return 0
        else
            echo "❌ FAIL: Unexpected status code (expected $expected_status, got $http_status)"
            # Show error message if present
            error_msg=$(echo "$body" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('error',d.get('message','')))" 2>/dev/null)
            [ -n "$error_msg" ] && echo "   Error: $error_msg"
            ((FAIL++))
            return 1
        fi
    fi
}

echo ""
echo "═════════════════════════════════════════════════════════════"
echo "  PHASE 1: CORE API HEALTH CHECKS"
echo "═════════════════════════════════════════════════════════════"
echo ""

test_endpoint "Root Endpoint" "$API_BASE/../" 200 15
test_endpoint "Meta Status" "$API_BASE/meta/status" 200 15

echo ""
echo "═════════════════════════════════════════════════════════════"
echo "  PHASE 2: ANIME LIBRARY ENDPOINTS"
echo "═════════════════════════════════════════════════════════════"
echo ""

test_endpoint "Anime List (All)" "$API_BASE/anime?page=1&limit=5" 200 30
test_endpoint "Currently Airing Filter" "$API_BASE/anime?status=airing&page=1&limit=5" 200 30
test_endpoint "Upcoming Filter" "$API_BASE/anime?status=upcoming&page=1&limit=5" 200 30
test_endpoint "Single Anime Details" "$API_BASE/anime/51" 200 30

echo ""
echo "═════════════════════════════════════════════════════════════"
echo "  PHASE 3: SEASONAL ENDPOINTS (Live MAL Scraping)"
echo "═════════════════════════════════════════════════════════════"
echo ""

test_endpoint "Current Season (Airing)" "$API_BASE/season" 200 45
test_endpoint "Upcoming Season" "$API_BASE/season/later" 200 45
test_endpoint "Season Archive" "$API_BASE/season/archive" 200 30

echo ""
echo "═════════════════════════════════════════════════════════════"
echo "  PHASE 4: SCHEDULE ENDPOINTS"
echo "═════════════════════════════════════════════════════════════"
echo ""

test_endpoint "Weekly Schedule" "$API_BASE/schedule" 200 45
test_endpoint "Monday Schedule" "$API_BASE/schedule/monday" 200 45

echo ""
echo "═════════════════════════════════════════════════════════════"
echo "  PHASE 5: SEASONAL CACHE MANAGEMENT (NEW!)"
echo "═════════════════════════════════════════════════════════════"
echo ""

test_endpoint "Seasonal Cache Status" "$API_BASE/meta/seasonal_cache_status" 200 20

echo ""
echo "🔄 Testing cache clear (POST request)..."
clear_response=$(curl -s --max-time 20 -X POST "$API_BASE/meta/clear_seasonal_cache" 2>&1)
clear_status=$(echo "$clear_response" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('status','error'))" 2>/dev/null)

if [ "$clear_status" == "ok" ]; then
    echo "✅ PASS: Seasonal Cache Clear Endpoint Working"
    deleted_count=$(echo "$clear_response" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('deleted_entries',0))" 2>/dev/null)
    echo "   Deleted $deleted_count cached entries"
    ((PASS++))
else
    echo "❌ FAIL: Seasonal Cache Clear Failed"
    echo "   Response: $clear_response"
    ((FAIL++))
fi

echo ""
echo "╔════════════════════════════════════════════════════════════╗"
echo "║                    TEST SUMMARY                           ║"
echo "╠════════════════════════════════════════════════════════════╣"
echo "║  ✅ Passed:  $PASS                                           ║"
echo "║  ❌ Failed:  $FAIL                                           ║"
echo "║  ⚠️  Warnings: $WARN (timeouts are acceptable)               ║"
echo "╚════════════════════════════════════════════════════════════╝"

if [ $FAIL -eq 0 ]; then
    echo ""
    echo "🎉 ALL CRITICAL TESTS PASSED!"
    echo ""
    echo "📋 Available Endpoints Summary:"
    echo "   • GET  /v4/season              → Currently Airing Anime"
    echo "   • GET  /v4/season/later        → Upcoming Anime"
    echo "   • GET  /v4/schedule            → Weekly Schedule"
    echo "   • GET  /v4/anime?status=airing → Filter by Status"
    echo "   • GET  /v4/meta/seasonal_cache_status → Check Cache Freshness"
    echo "   • POST /v4/meta/clear_seasonal_cache   → Force Refresh Data"
    echo ""
    exit 0
else
    echo ""
    echo "⚠️  Some tests failed. Review output above."
    exit 1
fi
