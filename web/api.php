<?php

namespace MRBS;

require 'defaultincludes.inc';
// require 'mrbs_sql.inc';

// Phase 1: check authentication and authorization.
$secret_token = getenv('MRBS_API_SECRET_TOKEN');
if (!$secret_token) {
    http_response_code(500);
    write_response_json(['error' => 'API secret token not set']);
    exit;
}

// Check Authorization header
$headers = getallheaders();
$auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? null;
$expected = 'Bearer ' . $secret_token;
if ($auth_header !== $expected) {
    http_response_code(401);
    write_response_json(['error' => 'Unauthorized']);
    exit;
}

// Phase 2: Parse the request
switch ($_GET['q']) {
    case 'query':
        // Query available rooms in a given time slot. 
        handle_query();
        break;

    case 'book':
        // Book a room for a given time slot.
        handle_book();
        break;

    case 'details':
        // Get details of a booking.
        handle_details();
        break;

    default:
        // Invalid request.
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request']);
        exit;
}

function write_response_json($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
}

function handle_query() {
    $start_time = $_GET['start_time'] ?? null;
    $end_time = $_GET['end_time'] ?? null;

    if (!$start_time || !$end_time) {
        http_response_code(400);
        write_response_json(['error' => 'Missing start_time or end_time']);
        exit;
    }

    // Obtain the list of all available rooms
    $sql = "SELECT id, area_id, room_name, capacity FROM " . _tbl('room') . " WHERE disabled=0;";
    $res = db()->query($sql);
    if (! $res) {
        http_response_code(500);
        write_response_json(['error' => 'Database query failed']);
        exit;
    }
    else {
        $rooms = [];
        while (false !== ($row = $res->next_row_keyed())) {
            $rooms[] = [
                'id' => $row['id'],
                'area_id' => $row['area_id'],
                'name' => $row['room_name'],
                'capacity' => (int)$row['capacity'],
            ];
        }
    }

    $sql = "SELECT DISTINCT E.room_id FROM " . _tbl('entry') . " E, " . _tbl('room') . " R
           WHERE E.room_id=R.id
             AND start_time < ?
             AND end_time > ?;";
    $sql_params = [$end_time, $start_time];
    $res = db()->query($sql, $sql_params);

    $booked_ids = [];
    if ($res) {
        while (false !== ($row = $res->next_row_keyed())) {
            $booked_ids[] = $row['room_id'];
        }
    }
    else {
        http_response_code(500);
        write_response_json(['error' => 'Database query failed']);
        exit;
    }
    
    // Remove all rooms that have a booking
    $rooms = array_filter($rooms, function($room) use ($booked_ids) {
        return !in_array($room['id'], $booked_ids);
    });

    $rooms = array_values($rooms);

    write_response_json($rooms);
    exit;
}

function handle_book() {
    // Only allow POST requests for booking.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        write_response_json(['error' => 'Method not allowed']);
        exit;
    }

    // Parse the booking from the POST JSON data.
    $booking = json_decode(file_get_contents('php://input'), true);

    $required_fields = ['room_id', 'start_time', 'end_time', 'name'];

    // Validate that all required fields are present.
    if (!is_array($booking) || count(array_intersect_key(array_flip($required_fields), $booking)) !== count($required_fields)) {
        http_response_code(400);
        write_response_json(['error' => 'Missing required booking parameters']);
        exit;
    }

    $booking['type'] = 'I'; // Internal booking are the only type supported by the API.
    $booking['create_by'] = 'api-user';
    $repeat_type = RepeatRule::NONE;
    $repeat_interval = 1;
    $repeat_end_time = null;
    $booking['repeat_rule'] = new RepeatRule();
    $booking['repeat_rule']->setType($repeat_type);
    $booking['repeat_rule']->setInterval($repeat_interval);
    $booking['repeat_rule']->setEndDate($repeat_end_time);
    
    $res = mrbsMakeBookings([ $booking ]);

    if (array_key_exists('new_details', $res)) {
        // Successfully booked.
        write_response_json([
            'status' => 'success',
            'booking' => $res['new_details'][0],
        ]);
    }
    else {
        // Booking failed.
        http_response_code(500);
        write_response_json([
            'error' => 'Booking failed',
            'violations' => $res['violations'] ?? [],
            'conflicts' => $res['conflicts'] ?? [],
        ]);
        exit;
    }
}

function handle_details() {
    // Simulate fetching booking details.
    $entry_id = $_GET['id'];

    $entry = get_entry_by_id($entry_id);
    if (! $entry) {
        http_response_code(404);
        write_response_json(['error' => 'Booking not found']);
        exit;
    }
    
    write_response_json($entry);
}

?>
