<br/><br/>

<div class="row">
	<div class="col-md-12">
		<h2>Native Social Buttons</h2>
		<p>These are social sharing buttons that are generated by loading scripts from the social network's remote servers.</p>

		<div class="entry-social">
			<div class="sharing-links">
				<?php nebula_facebook_share(true); ?>
				<?php nebula_facebook_like(true); ?>
				<?php nebula_facebook_both(true); ?>
			</div>
		</div>

		<div class="entry-social">
			<div class="sharing-links">
				<?php nebula_twitter_tweet(true); ?>
				<?php nebula_twitter_follow(true, '@GreatBlakes'); ?>
			</div>
		</div>

		<div class="entry-social">
			<div class="sharing-links">
				<?php nebula_linkedin_share(true); ?>
				<?php nebula_linkedin_follow(true); ?>
			</div>
		</div>

		<div class="entry-social">
			<div class="sharing-links">
				<?php nebula_google_plus(true); ?>

				<?php nebula_pinterest_pin(true); ?>
			</div>
		</div>
	</div><!--/col-->
</div><!--/row-->

<br/><br/><hr/><br/><br/>

<div class="row">
	<div class="col-md-12">
		<h2>Custom Social Buttons</h2>
		<p>These are locally stored social sharing buttons that have hard-coded hrefs. These hrefs can also be modified with javascript to dynamically pull in page titles, etc. There should always be backup hrefs hard-coded in case JS is disabled, these can at least share something.</p>
		<p>
			<a class="fbshare" href="http://www.facebook.com/sharer.php?u=<?php echo the_permalink(); ?>&t=<?php wp_title('-', true, 'right'); ?>" target="_blank">Facebook Share</a>,
			<a class="twshare" href="https://twitter.com/intent/tweet?text=<?php wp_title('-', true, 'right'); ?>&url=<?php echo the_permalink(); ?>" target="_blank">Twitter</a>,
			<a class="gshare" href="https://plus.google.com/share?url=<?php echo the_permalink(); ?>" target="_blank">Google+</a>,
			<a class="lishare" href="http://www.linkedin.com/shareArticle?mini=true&url=<?php echo the_permalink(); ?>&title=<?php wp_title('-', true, 'right'); ?>" target="_blank">LinkedIn</a>,
			<a class="emshare" href="mailto:?subject=<?php wp_title('-', true, 'right'); ?>&body=<?php echo the_permalink(); ?>" target="_blank">Email</a>
		</p>
	</div><!--/col-->
</div><!--/row-->