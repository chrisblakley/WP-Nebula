<style>
	.example1 {border: 1px solid #aaa; padding: 10px 15px;}
	.example2 {border: 1px solid #aaa; padding: 10px 15px;}
		.example2 li {margin-bottom: 15px;}

	.twitter-user-photo {float: left; margin: 6px 10px 0 0;}
	.twitter-posted-on {font-size: smaller; color: #888; white-space: nowrap;}
</style>


<script>
	jQuery(document).ready(function(){

		//Example 1
		//Fill pre-existing HTML with tweet data. This is good for displaying a single, latest tweet.
		jQuery.ajax({
			type: "POST",
			url: bloginfo["admin_ajax"],
			data: {
				action: 'nebula_twitter_cache',
				data: {
					'username': 'Great_Blakes',
					'listname': 'nebula',
					'numbertweets': 5,
					'includeretweets': 1,
				},
			},
			success: function(response){
				response = JSON.parse(response);
				jQuery('#tweet_user_photo1').attr('href', 'https://twitter.com/' + response[0].user.screen_name).append('<img src="' + response[0].user.profile_image_url + '" title="' + response[0].user.description + '" />');
				jQuery('#tweet_user1').attr('href', 'https://twitter.com/' + response[0].user.screen_name).text('@' + response[0].user.screen_name);

				var tweetTime = new Date(Date.parse(response[0].created_at.replace(/( \+)/, ' UTC$1'))); //UTC for IE8
				jQuery('#tweet_body1').html(tweetLinks(response[0].text)).append(" <span class='twitter-posted-on'><i class='fa fa-clock-o'></i> " + timeAgo(tweetTime) + "</span>");
			},
			error: function(MLHttpRequest, textStatus, errorThrown){
				ga('send', 'event', 'Error', 'AJAX Error', 'Twitter Feed');
			},
			timeout: 60000
		});


		//Example 2
		//Generate the markup within a UL to display tweets. This method is good for showing multiple tweets.
		jQuery.ajax({
			type: "POST",
			url: bloginfo["admin_ajax"],
			data: {
				action: 'nebula_twitter_cache',
				data: {
					'username': 'Great_Blakes',
					'listname': 'nebula',
					'numberTweets': 5,
					'includeRetweets': 1,
				},
			},
			success: function(response){
				response = JSON.parse(response);
				jQuery.each(response, function(i){
					//console.debug(response[i]); //Just to show all the data that is available.
					var tweetTime = new Date(Date.parse(response[i].created_at.replace(/( \+)/, ' UTC$1'))); //UTC for IE8
					jQuery('.example2').append('<li><a class="twitter-user-photo" href="https://twitter.com/' + response[i].user.screen_name + '" target="_blank"><img src="' + response[i].user.profile_image_url + '" title="' + response[i].user.description + '" /></a><strong><a href="https://twitter.com/' + response[i].user.screen_name + '" target="_blank">@' + response[i].user.screen_name + '</a></strong><br/><span>' + tweetLinks(response[i].text) + ' <span class="twitter-posted-on"><i class="fa fa-clock-o"></i> ' + timeAgo(tweetTime) + '</span></span></li>');
				});
			},
			error: function(MLHttpRequest, textStatus, errorThrown){
				ga('send', 'event', 'Error', 'AJAX Error', 'Twitter Feed');
			},
			timeout: 60000
		});
	});
</script>


<div class="row">
	<div class="sixteen columns">

		<div>
			<strong>Example 1</strong><br/>
			<span>Fill pre-existing HTML with tweet data. This is good for displaying a single, latest tweet.</span>
		</div>
		<p class="example1">
			<a id="tweet_user_photo1" class="twitter-user-photo" href="#" target="_blank"></a>
			<strong><a id="tweet_user1" target="_blank" href="#">Loading Tweets...</a></strong><br/>
			<span id="tweet_body1"></span>
		</p>

		<br/>

		<div>
			<strong>Example 2</strong><br/>
			<span>Generate the markup within a UL to display tweets. This method is good for showing multiple tweets.</span>
		</div>
		<ul class="example2"></ul>

	</div><!--/columns-->
</div><!--/row-->