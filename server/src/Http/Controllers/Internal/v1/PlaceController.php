<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\PlaceExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\Geocoding;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\Internal\BulkDeleteRequest;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
// additions
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class PlaceController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'place';

    // /**
    //  * Quick search places for selection.
    //  *
    //  * @return \Illuminate\Http\Response
    //  */
    // public function search(Request $request)
    // {
    //     $searchQuery = $request->searchQuery();
    //     $limit       = $request->input('limit', 30);
    //     $geo         = $request->boolean('geo');
    //     $latitude    = $request->input('latitude');
    //     $longitude   = $request->input('longitude');

    //     $query = Place::where('company_uuid', session('company'))
    //         ->whereNull('deleted_at')
    //         ->search($searchQuery);

    //     if ($latitude && $longitude) {
    //         $point = new Point($latitude, $longitude);
    //         $query->orderByDistanceSphere('location', $point, 'asc');
    //     } else {
    //         $query->orderBy('name', 'desc');
    //     }

    //     if ($limit) {
    //         $query->limit($limit);
    //     }

    //     $results = $query->get();

    //     if ($geo) {
    //         if ($searchQuery) {
    //             try {
    //                 $geocodingResults = Geocoding::query($searchQuery, $latitude, $longitude);

    //                 foreach ($geocodingResults as $result) {
    //                     $results->push($result);
    //                 }
    //             } catch (\Throwable $e) {
    //                 return response()->error($e->getMessage());
    //             }
    //         } elseif ($latitude && $longitude) {
    //             try {
    //                 $geocodingResults = Geocoding::reverseFromCoordinates($latitude, $longitude, $searchQuery);

    //                 foreach ($geocodingResults as $result) {
    //                     $results->push($result);
    //                 }
    //             } catch (\Throwable $e) {
    //                 return response()->error($e->getMessage());
    //             }
    //         }
    //     }

    //     return response()->json($results)->withHeaders(['Cache-Control' => 'no-cache']);
    // }


    // _______________________Modified Code, OSM____________________________

    /**
     * Quick search places for selection.
     *
     * @return \Illuminate\Http\Response
     */
    public function search(Request $request)
    {
        $searchQuery = $request->input('searchQuery');
        $limit       = $request->input('limit', 30);
        $geo         = $request->boolean('geo');
        $latitude    = $request->input('latitude');
        $longitude   = $request->input('longitude');

        $query = Place::where('company_uuid', session('company'))
            ->whereNull('deleted_at')
            ->search($searchQuery);

        if ($latitude && $longitude) {
            $point = new Point($latitude, $longitude);
            $query->orderByDistanceSphere('location', $point, 'asc');
        } else {
            $query->orderBy('name', 'desc');
        }

        if ($limit) {
            $query->limit($limit);
        }

        $results = $query->get();

        if ($geo) {
            $client = new Client();

            if ($searchQuery) {
                $url = 'https://nominatim.openstreetmap.org/search';
                $params = [
                    'q' => $searchQuery,
                    'format' => 'json',
                    'limit' => $limit,
                    'viewbox' => "$longitude,$latitude,$longitude,$latitude",
                    'bounded' => 1,
                    'addressdetails' => 1
                ];
            } elseif ($latitude && $longitude) {
                $url = 'https://nominatim.openstreetmap.org/reverse';
                $params = [
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'format' => 'json',
                    'addressdetails' => 1
                ];
            }

            try {
                $response = $client->request('GET', $url, ['query' => $params]);
                $geocodingResults = json_decode($response->getBody(), true);

                foreach ($geocodingResults as $result) {
                    $place = new Place();
                    $place->name = $result['display_name'];
                    $place->latitude = $result['lat'];
                    $place->longitude = $result['lon'];
                    $results->push($place);
                }

                Log::info('Geocoding Results:', $geocodingResults);
            } catch (RequestException $e) {
                Log::error('Geocoding API Error:', ['message' => $e->getMessage()]);
                return response()->json(['error' => $e->getMessage()], 500);
            }
        }

        Log::info('Search Results:', $results->toArray());

        return response()->json($results);
    }

    // ______________________________________________________________________

    /**
     * Search using geocoder for addresses.
     *
     * @return \Illuminate\Http\Response
     */
    public function geocode(ExportRequest $request)
    {
        $searchQuery = $request->searchQuery();
        $latitude    = $request->input('latitude', false);
        $longitude   = $request->input('longitude', false);
        $results     = collect();

        if ($searchQuery) {
            try {
                $geocodingResults = Geocoding::query($searchQuery, $latitude, $longitude);

                foreach ($geocodingResults as $result) {
                    $results->push($result);
                }
            } catch (\Throwable $e) {
                return response()->error($e->getMessage());
            }
        } elseif ($latitude && $longitude) {
            try {
                $geocodingResults = Geocoding::reverseFromCoordinates($latitude, $longitude, $searchQuery);

                foreach ($geocodingResults as $result) {
                    $results->push($result);
                }
            } catch (\Throwable $e) {
                return response()->error($e->getMessage());
            }
        }

        return response()->json($results)->withHeaders(['Cache-Control' => 'no-cache']);
    }

    /**
     * Export the places to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public function export(ExportRequest $request)
    {
        $format   = $request->input('format', 'xlsx');
        $fileName = trim(Str::slug('places-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new PlaceExport(), $fileName);
    }

    /**
     * Bulk deletes resources.
     *
     * @return \Illuminate\Http\Response
     */
    public function bulkDelete(BulkDeleteRequest $request)
    {
        $ids = $request->input('ids', []);

        if (!$ids) {
            return response()->error('Nothing to delete.');
        }

        /**
         * @var \Fleetbase\Models\Place
         */
        $count   = Place::whereIn('uuid', $ids)->count();
        $deleted = Place::whereIn('uuid', $ids)->delete();

        if (!$deleted) {
            return response()->error('Failed to bulk delete places.');
        }

        return response()->json(
            [
                'status'  => 'OK',
                'message' => 'Deleted ' . $count . ' places',
            ],
            200
        );
    }

    /**
     * Get all avatar options for an vehicle.
     *
     * @return \Illuminate\Http\Response
     */
    public function avatars()
    {
        $options = Place::getAvatarOptions();

        return response()->json($options);
    }
}
