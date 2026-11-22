<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

/**
 * What a webhook sends (spec 7.12): the same data for every format, never the reporter's e-mail, IP address,
 * screenshot or technical context. Text is sent as plain text: Slack's reserved characters and the Markdown
 * characters of Discord and Teams are escaped.
 */
final class Webhook_Payload {

	private const EXCERPT_LENGTH = 300;

	/** Discord's limit for an embed field value. */
	private const DISCORD_FIELD_MAX = 1024;

	/**
	 * @param string      $event  created, status_changed, assigned or test.
	 * @param array|null  $report A stored report (find()); null for a test.
	 * @param string|null $change The change, such as "Status: New → Fixed" (Report_Format::change()).
	 * @return array<string,mixed> The generic JSON structure.
	 */
	public static function data( string $event, ?array $report, ?string $change = null ): array {
		$data = array(
			'event'     => 'test' === $event ? 'test' : 'report.' . $event,
			'site'      => array(
				'name' => Report_Format::site_name(),
				'url'  => home_url( '/' ),
			),
			'timestamp' => gmdate( 'c' ),
			'change'    => $change,
			'report'    => null,
		);
		if ( null === $report ) {
			return $data;
		}
		$id       = (int) $report['id'];
		$assignee = (int) ( $report['assigned_to'] ?? 0 );

		$data['report'] = array(
			'id'          => $id,
			'title'       => Report_Format::title( $id ),
			'description' => Report_Format::excerpt( (string) $report['description'], self::EXCERPT_LENGTH ),
			'page_url'    => (string) $report['page_url'],
			'status'      => array(
				'key'   => (string) $report['status'],
				'label' => Statuses::label( (string) $report['status'] ),
			),
			'priority'    => array(
				'key'   => (string) $report['priority'],
				'label' => Priorities::label( (string) $report['priority'] ),
			),
			'assignee'    => $assignee > 0 ? Team::name( $assignee ) : null,
			'admin_url'   => Report_Format::link( $id ),
		);
		return $data;
	}

	/**
	 * One chat message for the $count reports a bulk action changed (Webhook_Sender::batch()).
	 *
	 * @param string $value The new status name, or the name of the new assignee.
	 * @return array<string,mixed> data() of no report, plus "summary": count, text and the address of the list.
	 */
	public static function summary( string $event, int $count, string $value ): array {
		$text = 'assigned' === $event
			/* translators: 1: number of reports, 2: name of the person they are assigned to. */
			? sprintf( _n( '%1$d report assigned to %2$s', '%1$d reports assigned to %2$s', $count, 'bug-spotter' ), $count, $value )
			/* translators: 1: number of reports, 2: their new status. */
			: sprintf( _n( '%1$d report: status → %2$s', '%1$d reports: status → %2$s', $count, 'bug-spotter' ), $count, $value );

		$data            = self::data( $event, null );
		$data['summary'] = array(
			'count' => $count,
			'text'  => $text,
			'url'   => Report_Format::list_link(),
		);
		return $data;
	}

	/** The JSON body of $data in $format (one of Webhooks::FORMATS). @param array<string,mixed> $data */
	public static function body( string $format, array $data ): string {
		$body = match ( $format ) {
			'slack'   => self::slack( $data ),
			'discord' => self::discord( $data ),
			'teams'   => self::teams( $data ),
			default   => $data,
		};
		return (string) wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/** First line of a chat message. @param array<string,mixed> $data */
	private static function headline( array $data ): string {
		if ( isset( $data['summary'] ) ) {
			return $data['summary']['text'];
		}
		$title = (string) ( $data['report']['title'] ?? '' );
		return match ( $data['event'] ) {
			/* translators: %s: report title, such as "Bug #12". */
			'report.created'        => sprintf( __( 'New report: %s', 'bug-spotter' ), $title ),
			/* translators: %s: report title, such as "Bug #12". */
			'report.status_changed' => sprintf( __( 'Status changed: %s', 'bug-spotter' ), $title ),
			/* translators: %s: report title, such as "Bug #12". */
			'report.assigned'       => sprintf( __( 'Report assigned: %s', 'bug-spotter' ), $title ),
			/* translators: %s: site name. */
			default                 => sprintf( __( 'Test message from Bug Spotter on %s. The webhook works.', 'bug-spotter' ), $data['site']['name'] ),
		};
	}

	/** Label => value lines of a chat message (none for a test or a summary). @param array<string,mixed> $data @return array<string,string> */
	private static function facts( array $data ): array {
		$r = $data['report'];
		if ( null === $r ) {
			return array();
		}
		$facts = array(
			__( 'Description', 'bug-spotter' ) => $r['description'],
			__( 'Page', 'bug-spotter' )        => '' !== $r['page_url'] ? $r['page_url'] : '—',
			__( 'Status', 'bug-spotter' )      => $r['status']['label'],
			__( 'Priority', 'bug-spotter' )    => $r['priority']['label'],
			__( 'Assigned to', 'bug-spotter' ) => $r['assignee'] ?? __( 'Unassigned', 'bug-spotter' ),
		);
		if ( null !== $data['change'] ) {
			$facts[ __( 'Change', 'bug-spotter' ) ] = $data['change'];
		}
		return $facts;
	}

	/** Where the message leads: the report, or the list for a summary; null for a test. @param array<string,mixed> $data @return array{title:string,url:string,label:string}|null */
	private static function link( array $data ): ?array {
		if ( null !== $data['report'] ) {
			return array(
				'title' => $data['report']['title'],
				'url'   => $data['report']['admin_url'],
				'label' => __( 'Open the report', 'bug-spotter' ),
			);
		}
		if ( isset( $data['summary'] ) ) {
			return array(
				'title' => $data['summary']['text'],
				'url'   => $data['summary']['url'],
				'label' => __( 'Open the list', 'bug-spotter' ),
			);
		}
		return null;
	}

	/** Slack incoming webhook: a fallback text and one mrkdwn section. @param array<string,mixed> $data */
	private static function slack( array $data ): array {
		$headline = self::headline( $data );
		$text     = '*' . self::slack_escape( $headline ) . '*';
		foreach ( self::facts( $data ) as $label => $value ) {
			$text .= "\n*" . self::slack_escape( $label ) . ':* ' . self::slack_escape( $value );
		}
		$link = self::link( $data );
		if ( null !== $link ) {
			$text .= "\n<" . self::slack_escape( $link['url'] ) . '|' . self::slack_escape( $link['label'] ) . '>';
		}
		return array(
			'text'   => $headline,
			'blocks' => array(
				array(
					'type' => 'section',
					'text' => array(
						'type' => 'mrkdwn',
						'text' => $text,
					),
				),
			),
		);
	}

	/** Slack reads &, < and > as markup: https://api.slack.com/reference/surfaces/formatting#escaping */
	private static function slack_escape( string $text ): string {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
	}

	/** Discord and Teams read Markdown: a backslash keeps \ * _ [ ] ( ) < > ` as typed. */
	private static function markdown_escape( string $text ): string {
		return (string) preg_replace( '/([\\\\*_\[\]()<>`])/', '\\\\$1', $text );
	}

	/** Discord webhook: a message and one embed; mentions (such as @everyone in a description) never ping. @param array<string,mixed> $data */
	private static function discord( array $data ): array {
		$body = array(
			'content'          => self::markdown_escape( self::headline( $data ) ),
			'allowed_mentions' => array( 'parse' => array() ),
			'embeds'           => array(),
		);
		$link = self::link( $data );
		if ( null === $link ) {
			return $body;
		}
		$fields = array();
		foreach ( self::facts( $data ) as $label => $value ) {
			$fields[] = array(
				'name'  => $label,
				'value' => Report_Format::excerpt( self::markdown_escape( $value ), self::DISCORD_FIELD_MAX - 1 ),
			);
		}
		$body['embeds'][] = array(
			'title'     => $link['title'],
			'url'       => $link['url'],
			'fields'    => $fields,
			'timestamp' => $data['timestamp'],
		);
		return $body;
	}

	/**
	 * Microsoft Teams: an Adaptive Card wrapped in a "message", the format the Teams "Workflows" webhooks expect
	 * (they replace the retired Office 365 connectors). @param array<string,mixed> $data
	 */
	private static function teams( array $data ): array {
		$facts = array();
		foreach ( self::facts( $data ) as $label => $value ) {
			$facts[] = array(
				'title' => $label,
				'value' => self::markdown_escape( $value ),
			);
		}
		$card = array(
			'$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
			'type'    => 'AdaptiveCard',
			'version' => '1.4',
			'body'    => array(
				array(
					'type'   => 'TextBlock',
					'text'   => self::markdown_escape( self::headline( $data ) ),
					'weight' => 'Bolder',
					'size'   => 'Medium',
					'wrap'   => true,
				),
			),
		);
		if ( $facts ) {
			$card['body'][] = array(
				'type'  => 'FactSet',
				'facts' => $facts,
			);
		}
		$link = self::link( $data );
		if ( null !== $link ) {
			$card['actions'] = array(
				array(
					'type'  => 'Action.OpenUrl',
					'title' => $link['label'],
					'url'   => $link['url'],
				),
			);
		}
		return array(
			'type'        => 'message',
			'attachments' => array(
				array(
					'contentType' => 'application/vnd.microsoft.card.adaptive',
					'contentUrl'  => null,
					'content'     => $card,
				),
			),
		);
	}
}
